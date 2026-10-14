<?php
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// Passwordless auth: request emails a 4+4 code (15-minute expiry, single-use,
// hashed at rest); verify redeems it for a session. Requesting with a new
// email registers the account — codes prove ownership, so signup = login.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: /login.php?error=' . urlencode('Invalid request, please try again'));
    exit;
}

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
/** @var PDO $pdo */

// ====================== LOGOUT ======================
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    header('Location: /');
    exit;
}

$back = '/login.php';
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$backEmail = $back . '?email=' . urlencode($email);

// ====================== REQUEST: mail a fresh code ======================
if ($action === 'request') {
    if (($retry = rate_limit('login_code_req', 5, 60)) > 0) {
        header("Location: {$backEmail}&error=" . urlencode("Too many attempts, try again in {$retry}s"));
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: {$back}&error=" . urlencode('Invalid email format'));
        exit;
    }
    if (rate_limit('login_code_req_email', 3, 300, $email) > 0) {
        header("Location: {$backEmail}&error=" . urlencode('Please wait before requesting another code'));
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $exists = (bool)$stmt->fetch();

        if ($exists) {
            if (!send_login_code($email)) {
                header("Location: {$backEmail}&error=" . urlencode('Failed to send the code email, try again later'));
                exit;
            }
        } else {
            // New email = registration. Create the user + send the code
            // transactionally so a mail() failure doesn't strand the row.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (:email, NULL)")
                    ->execute(['email' => $email]);
                if (!send_login_code($email)) {
                    $pdo->rollBack();
                    header("Location: {$backEmail}&error=" . urlencode('Failed to send the code email, try again later'));
                    exit;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        // Identical response whether the email was new or existing — no
        // user enumeration through this endpoint.
        header("Location: {$backEmail}&sent=1");
        exit;
    } catch (Throwable $e) {
        error_log('Login code request error: ' . redact_log_value($e->getMessage()));
        header("Location: {$backEmail}&error=" . urlencode('Database error'));
        exit;
    }
}

// ====================== VERIFY: redeem the code ======================
if ($action === 'verify') {
    if (($retry = rate_limit('login_code_verify', 10, 60)) > 0) {
        header("Location: {$backEmail}&error=" . urlencode("Too many attempts, try again in {$retry}s"));
        exit;
    }

    $code = login_code_normalize((string)($_POST['code'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) !== 8) {
        header("Location: {$backEmail}&sent=1&error=" . urlencode('Enter the 8-character code from your email'));
        exit;
    }

    try {
        if (!redeem_login_code($email, $code)) {
            header("Location: {$backEmail}&sent=1&error=" . urlencode('Invalid or expired code'));
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            header("Location: {$backEmail}&sent=1&error=" . urlencode('Invalid or expired code'));
            exit;
        }

        // A redeemed code proves email ownership — mark verified.
        $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = :id AND is_verified = 0")
            ->execute(['id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        header('Location: /cabinet.php');
        exit;
    } catch (Throwable $e) {
        error_log('Login code verify error: ' . redact_log_value($e->getMessage()));
        header("Location: {$backEmail}&sent=1&error=" . urlencode('Database error'));
        exit;
    }
}

header('Location: /login.php?error=' . urlencode('Invalid request, please try again'));
