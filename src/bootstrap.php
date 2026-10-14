<?php

// Application bootstrap. Entry points load this file; it must never
// be reachable over HTTP (keep src/ outside the docroot).
if (!defined('APP_SRC')) {
    define('APP_SRC', __DIR__);
}
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Pure helpers with no DB/session dependency — also loaded standalone by tests.
require_once __DIR__ . '/helpers.php';
// Emailed login codes — uses the global $pdo built below; lazy per call,
// safe to load pre-connect.
require_once __DIR__ . '/email_codes.php';
// Lifecycle → engine bridge (fire_lifecycle_event). No-ops until M3.
require_once __DIR__ . '/lifecycle.php';

// Suppress error output immediately — before session_start()/autoload can
// emit a warning into the response (which would flip headers_sent() and
// silently kill security headers). Re-applied after dotenv below so an
// .env-provided APP_DEBUG still takes effect.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Hardened session cookies for the web UI; skipped under CLI
// (cron scripts include this file too).
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
require_once APP_ROOT . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(APP_ROOT);
// safeLoad: absent .env is tolerated here — empty DB_* still fails loudly
// at the PDO connect below, and tests/CI inject env vars directly.
$dotenv->safeLoad();

// Never surface PHP errors in responses; detail goes to the error log.
// APP_DEBUG=true re-enables display for local development only.
$appDebug = env_val('APP_DEBUG', 'false') === 'true';
ini_set('display_errors', $appDebug ? '1' : '0');
if (!is_dir(APP_ROOT . '/logs')) {
    @mkdir(APP_ROOT . '/logs', 0750, true);
}
ini_set('error_log', APP_ROOT . '/logs/php_errors.log');

$db_host = env_val('DB_HOST', '');
$db_user = env_val('DB_USER', '');
$db_pass = env_val('DB_PASS', '');
$db_name = env_val('DB_NAME', '');

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    // Force UTC at DB level
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    error_log('DB connection failed: ' . redact_log_value($e->getMessage()));
    http_response_code(503);
    if (str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'error' => 'Service unavailable']));
    }
    die('Service unavailable');
}

// ====================== CSRF protection (cabinet session forms) ======================

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify() {
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent)) {
        return false;
    }
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}

function require_csrf_json() {
    if (!csrf_verify()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}


/**
 * Fixed-window rate limiter backed by the rate_limits table.
 * Returns seconds until the window resets when the limit is exceeded, 0 when allowed.
 * Fails open on DB errors (logged) so a limiter outage never takes the API down.
 */
function rate_limit($bucket, $maxAttempts, $windowSeconds, $key = null) {
    global $pdo;

    $key = $key ?? client_ip();

    try {
        $pdo->prepare("
            INSERT INTO rate_limits (bucket, rate_key, attempts, window_start)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                attempts = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, 1, attempts + 1),
                window_start = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?, NOW(), window_start)
        ")->execute([$bucket, $key, $windowSeconds, $windowSeconds]);

        $stmt = $pdo->prepare("
            SELECT attempts, TIMESTAMPDIFF(SECOND, window_start, NOW()) AS age
            FROM rate_limits
            WHERE bucket = ? AND rate_key = ?
        ");
        $stmt->execute([$bucket, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && (int)$row['age'] < $windowSeconds && (int)$row['attempts'] > $maxAttempts) {
            return $windowSeconds - (int)$row['age'];
        }
    } catch (Throwable $e) {
        error_log('rate_limit error: ' . redact_log_value($e->getMessage()));
    }

    return 0;
}

/** Reply 429 + Retry-After and exit when the bucket is over the limit. */
function rate_limit_or_429($bucket, $maxAttempts, $windowSeconds, $key = null) {
    $retry = rate_limit($bucket, $maxAttempts, $windowSeconds, $key);
    if ($retry > 0) {
        http_response_code(429);
        header('Retry-After: ' . $retry);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Too many requests', 'retry_after' => $retry]);
        exit;
    }
}


// ====================== Token digests ======================

// Digest for the player-token write path: null when hashing is disabled
// (TOKEN_PEPPER unset) — token column then stores plaintext.
function player_token_digest($token) {
    return token_digest($token);
}

// Verify a presented player token. Hash-first with plaintext fallback so the
// platform also works when TOKEN_PEPPER is not configured (dev installs).
function find_player_by_token($playerToken) {
    global $pdo;
    if (!is_string($playerToken) || $playerToken === '') {
        return false;
    }

    $digest = token_digest($playerToken);
    if ($digest !== null) {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE token_hash = ?");
        $stmt->execute([$digest]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($player) {
            return $player;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM players WHERE token = ?");
    $stmt->execute([$playerToken]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($player && $digest !== null && empty($player['token_hash'])) {
        $pdo->prepare("UPDATE players SET token_hash = ? WHERE id = ?")
            ->execute([$digest, $player['id']]);
    }
    return $player;
}

// Verify a game admin credential pair (api_key + private key). Hash-first
// with plaintext fallback — same pattern as player tokens.
function find_game_by_credentials($apiKey, $apiPrivateKey) {
    global $pdo;
    if (!is_string($apiKey) || !is_string($apiPrivateKey)
        || $apiKey === '' || $apiPrivateKey === '') {
        return false;
    }
    $stmt = $pdo->prepare("SELECT * FROM games WHERE api_key = ?");
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    if (!empty($row['api_private_key_hash'])) {
        $digest = token_digest($apiPrivateKey);
        return ($digest !== null && hash_equals($row['api_private_key_hash'], $digest)) ? $row : false;
    }

    return false;
}


// ====================== Secret storage ======================

// Symmetric store for secrets that must stay retrievable (game private keys —
// the owner can copy them again in the cabinet), so they can't be hash-only.
// Wrapped in AES-256-GCM under TOKEN_PEPPER ('enc:v1:' prefix); stored 'raw:'
// when no pepper is configured. Format is always self-describing.
function secret_store($plain) {
    if (!is_string($plain) || $plain === '') {
        return null;
    }
    $pepper = api_token_pepper();
    if ($pepper === '' || !function_exists('openssl_encrypt')) {
        return 'raw:' . $plain;
    }
    $key = hash('sha256', 'mlc-secret|' . $pepper, true);
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ct === false) {
        return 'raw:' . $plain;
    }
    return 'enc:v1:' . base64_encode($nonce . $tag . $ct);
}

// Inverse of secret_store. Returns null when the value cannot be recovered
// (e.g. enc:v1: stored but TOKEN_PEPPER since rotated/removed).
function secret_read($stored) {
    if (!is_string($stored) || $stored === '') {
        return null;
    }
    if (str_starts_with($stored, 'raw:')) {
        return substr($stored, 4);
    }
    if (str_starts_with($stored, 'enc:v1:')) {
        $pepper = api_token_pepper();
        $blob = base64_decode(substr($stored, 7), true);
        if ($pepper === '' || $blob === false || strlen($blob) < 29) {
            return null;
        }
        $key = hash('sha256', 'mlc-secret|' . $pepper, true);
        $pt = openssl_decrypt(
            substr($blob, 28),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($blob, 0, 12),
            substr($blob, 12, 16)
        );
        return $pt === false ? null : $pt;
    }
    return null;
}


// ====================== API auth (game + player) ======================

// Authenticate the game from X-Api-Token. Returns the games row or exits 401.
function api_auth_game() {
    $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($apiToken) || $apiToken === '') {
        respond_json(['success' => false, 'error' => 'API token is required'], 401);
    }
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM games WHERE api_key = ?");
    $stmt->execute([$apiToken]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) {
        respond_json(['success' => false, 'error' => 'Invalid API token'], 401);
    }
    return $game;
}

// Authenticate the calling player from X-Player-Token, scoped to the game.
// Returns the players row or exits 401/403.
function api_auth_player(array $game) {
    $token = $_SERVER['HTTP_X_PLAYER_TOKEN'] ?? '';
    if (!is_string($token) || $token === '') {
        respond_json(['success' => false, 'error' => 'Player token is required'], 401);
    }
    $player = find_player_by_token($token);
    if (!$player) {
        respond_json(['success' => false, 'error' => 'Invalid player token'], 401);
    }
    if ((int)$player['game_id'] !== (int)$game['id']) {
        respond_json(['success' => false, 'error' => 'Invalid player token'], 403);
    }
    return $player;
}

// The player's current room membership row, or null. Central so every
// endpoint reads membership the same way.
function player_room_membership($playerId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM room_players WHERE player_id = ?");
    $stmt->execute([(int)$playerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


// ====================== Security headers ======================

// Emit security headers for HTML pages. Call right after bootstrap is
// required, before any output. CSP uses a per-request nonce so inline
// scripts must carry a nonce attribute emitted by csp_nonce(). Pages have no
// inline event handlers (onclick etc.) — those stay blocked on purpose.
// 'wasm-unsafe-eval' is required by the Tailwind browser build (WASM).
function send_security_headers() {
    if (headers_sent($sentFile, $sentLine)) {
        // Log the output origin — a stray warning/echo before this call
        // silently strips every security header from the response.
        error_log("send_security_headers(): headers already sent at $sentFile:$sentLine");
        return;
    }
    $nonce = csp_nonce();
    header("Content-Security-Policy: default-src 'self'; " .
        "script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval' " .
            "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com https://unpkg.com; " .
        "style-src 'self' 'unsafe-inline' " .
            "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
        "img-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net; " .
        "worker-src 'self' blob:; object-src 'none'; " .
        "base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    // Always emitted (also over HTTP): browsers ignore HSTS on insecure
    // schemes, and .htaccess can't express HTTPS-only either.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}


// ====================== JSON API responses ======================

// Standard API JSON reply + exit. Every api/ endpoint funnels through this.
/** @return never */
function respond_json($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Emit the CORS headers shared by all api/ endpoints, handle OPTIONS
// preflight, and set JSON content type. Call at the top of each endpoint.
function api_headers() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Token, X-Api-Private-Token, X-Player-Token');
    header('Content-Type: application/json');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
