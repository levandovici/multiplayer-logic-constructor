<?php
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// Cabinet game management — session + CSRF required. JSON responses.
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
require_csrf_json();

/** @var PDO $pdo */
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$userId = (int)$_SESSION['user_id'];

function owned_game($gameId, $userId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$gameId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ====================== CREATE ======================
if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Game name is required (max 100 chars)']);
        exit;
    }
    if (($retry = rate_limit('game_create', 10, 3600)) > 0) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => "Too many games created, try again in {$retry}s"]);
        exit;
    }

    // Optional template — instant playable game seeded from templates/.
    $tplSlug = trim((string)($_POST['template'] ?? ''));
    $tpl = null;
    if ($tplSlug !== '') {
        $tpl = template_files($tplSlug);
        if ($tpl === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown template']);
            exit;
        }
        // Templates ship pre-validated, but re-validate — a broken template
        // must fail loudly here rather than publish a dead program.
        try {
            \Mlc\Engine\Program::fromJson($tpl['ast']);
        } catch (Throwable $e) {
            error_log('template invalid: ' . $tplSlug . ' ' . redact_log_value($e->getMessage()));
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Template is broken — please report this']);
            exit;
        }
    }

    $apiKey = generate_uuid();
    $privateKey = bin2hex(random_bytes(18));
    $digest = token_digest($privateKey);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO games (user_id, name, api_key, api_private_key,
                               api_private_key_hash, api_private_key_prefix,
                               api_private_key_suffix, api_private_key_enc)
            VALUES (:uid, :name, :api_key, NULL, :hash, :prefix, :suffix, :enc)
        ");
        $stmt->execute([
            'uid' => $userId,
            'name' => $name,
            'api_key' => $apiKey,
            'hash' => $digest,
            'prefix' => substr($privateKey, 0, 8),
            'suffix' => substr($privateKey, -4),
            'enc' => secret_store($privateKey),
        ]);
        $gameId = (int)$pdo->lastInsertId();

        if ($tpl !== null) {
            $pdo->prepare("INSERT INTO programs (game_id, name, draft_workspace, draft_ast) VALUES (?, 'main', ?, ?)")
                ->execute([$gameId, $tpl['workspace'], $tpl['ast']]);
            $programId = (int)$pdo->lastInsertId();
            $pdo->prepare("
                INSERT INTO program_versions (program_id, version, ast, checksum, published_by)
                VALUES (?, 1, ?, ?, ?)
            ")->execute([$programId, $tpl['ast'], hash('sha256', $tpl['ast']), $userId]);
            $pdo->prepare("UPDATE games SET published_program_id = ? WHERE id = ?")
                ->execute([(int)$pdo->lastInsertId(), $gameId]);
        }
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'game' => [
                'id' => $gameId,
                'name' => $name,
                'api_key' => $apiKey,
                // Returned once at creation; afterwards only via reveal.
                'api_private_key' => $privateKey,
            ],
            'template' => $tplSlug !== '' ? $tplSlug : null,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('game create error: ' . redact_log_value($e->getMessage()));
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// ====================== REVEAL (owner re-copies private key) ======================
if ($action === 'reveal') {
    $game = owned_game((int)($_POST['game_id'] ?? 0), $userId);
    if (!$game) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Game not found']);
        exit;
    }
    $key = secret_read($game['api_private_key_enc'] ?? '');
    if ($key === null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Private key is not retrievable on this install — regenerate it']);
        exit;
    }
    echo json_encode(['success' => true, 'api_private_key' => $key]);
    exit;
}

// ====================== REGENERATE private key ======================
if ($action === 'regenerate') {
    $game = owned_game((int)($_POST['game_id'] ?? 0), $userId);
    if (!$game) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Game not found']);
        exit;
    }
    $privateKey = bin2hex(random_bytes(18));
    $pdo->prepare("
        UPDATE games SET api_private_key = NULL, api_private_key_hash = ?,
            api_private_key_prefix = ?, api_private_key_suffix = ?,
            api_private_key_enc = ?
        WHERE id = ?
    ")->execute([
        token_digest($privateKey),
        substr($privateKey, 0, 8),
        substr($privateKey, -4),
        secret_store($privateKey),
        $game['id'],
    ]);
    echo json_encode(['success' => true, 'api_private_key' => $privateKey]);
    exit;
}

// ====================== DELETE ======================
if ($action === 'delete') {
    $game = owned_game((int)($_POST['game_id'] ?? 0), $userId);
    if (!$game) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Game not found']);
        exit;
    }
    // Cascades: players, rooms, room_players, player_updates, programs,
    // state rows, scheduled events, action log.
    $pdo->prepare("DELETE FROM games WHERE id = ?")->execute([$game['id']]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
