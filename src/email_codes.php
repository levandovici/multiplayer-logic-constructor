<?php

// Emailed one-time login codes — passwordless sign-in IS registration here:
// requesting a code creates the account if absent, redeeming it proves
// email ownership and opens a session. Loaded by bootstrap.php.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

// Issue a fresh code: one active code per email, 15-minute expiry, stored
// hashed. Callers handle rate limiting and anti-enumeration. The row is
// deleted when the mail can't be sent so a retry isn't blocked by a dead code.
function send_login_code($email) {
    global $pdo;
    $code = login_code_generate();
    $digest = token_digest(login_code_normalize($code)) ?? hash('sha256', login_code_normalize($code));
    $pdo->prepare("
        INSERT INTO login_codes (email, code_hash, expires_at)
        VALUES (:email, :hash, NOW() + INTERVAL 15 MINUTE)
        ON DUPLICATE KEY UPDATE
            code_hash  = VALUES(code_hash),
            expires_at = VALUES(expires_at),
            attempts   = 0,
            created_at = NOW()
    ")->execute(['email' => $email, 'hash' => $digest]);

    $sent = send_mail(
        $email,
        "Your Multiplayer Logic Constructor login code",
        "Your login code is: $code\n\nIt expires in 15 minutes.\nIf you didn't request this code, you can ignore this email."
    );
    if (!$sent) {
        // Dev convenience: with no MTA, APP_DEBUG installs get the code in
        // the error log instead of a dead-end row. Never in production.
        if (env_val('APP_DEBUG', 'false') === 'true') {
            error_log("login code for $email: $code");
            return true;
        }
        $pdo->prepare("DELETE FROM login_codes WHERE email = :email")->execute(['email' => $email]);
    }
    return $sent;
}

// Verify a code: must be fresh and under the 5-attempt cap. Single-use —
// deleted on success, and on expiry or lockout; a miss increments attempts.
function redeem_login_code($email, $code) {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT code_hash, attempts, (expires_at > NOW()) AS fresh FROM login_codes WHERE email = :email"
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $digest = token_digest($code) ?? hash('sha256', $code);
    $valid = $row
        && (int)$row['fresh'] === 1
        && (int)$row['attempts'] < 5
        && hash_equals((string)$row['code_hash'], $digest);

    if ($row) {
        if ($valid || (int)$row['fresh'] !== 1 || (int)$row['attempts'] + 1 >= 5) {
            $pdo->prepare("DELETE FROM login_codes WHERE email = :email")->execute(['email' => $email]);
        } else {
            $pdo->prepare("UPDATE login_codes SET attempts = attempts + 1 WHERE email = :email")
                ->execute(['email' => $email]);
        }
    }
    return $valid;
}
