<?php

// seed-demo.php — create a demo game with the tic-tac-toe fixture published.
// Lets you exercise the full stack (register players, create room, fire
// actions) without touching the editor first.
//
//   php bin/seed-demo.php <owner-email>
//
// Re-running is a no-op when a "Tic-Tac-Toe Demo" game already exists for
// that user — it just reprints the credentials.

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Mlc\Engine\Program;

$email = $argv[1] ?? '';
if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "usage: php bin/seed-demo.php <owner-email>\n");
    exit(1);
}

/** @var PDO $pdo */
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();
if ($userId === false) {
    fwrite(STDERR, "no user with email $email — log into the cabinet once first\n");
    exit(1);
}

$stmt = $pdo->prepare("SELECT * FROM games WHERE user_id = ? AND name = 'Tic-Tac-Toe Demo'");
$stmt->execute([(int)$userId]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if ($game) {
    echo "Game already exists.\n";
    echo "  api_key: {$game['api_key']}\n";
    echo "  published_program_id: " . ($game['published_program_id'] ?? 'none') . "\n";
    exit(0);
}

$astJson = file_get_contents(APP_ROOT . '/tests/Fixtures/programs/tictactoe.json');
try {
    Program::fromJson((string)$astJson); // fail fast on a broken fixture
} catch (Throwable $e) {
    fwrite(STDERR, 'fixture invalid: ' . $e->getMessage() . "\n");
    exit(1);
}

$apiKey = generate_uuid();
$privateKey = bin2hex(random_bytes(18));

$pdo->beginTransaction();
try {
    $pdo->prepare("
        INSERT INTO games (user_id, name, api_key, api_private_key_hash,
                           api_private_key_prefix, api_private_key_suffix,
                           api_private_key_enc)
        VALUES (?, 'Tic-Tac-Toe Demo', ?, ?, ?, ?, ?)
    ")->execute([
        (int)$userId, $apiKey, token_digest($privateKey),
        substr($privateKey, 0, 8), substr($privateKey, -4),
        secret_store($privateKey),
    ]);
    $gameId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO programs (game_id, name, draft_ast) VALUES (?, 'main', ?)")
        ->execute([$gameId, $astJson]);
    $programId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO program_versions (program_id, version, ast, checksum, published_by)
        VALUES (?, 1, ?, ?, ?)
    ")->execute([$programId, $astJson, hash('sha256', (string)$astJson), (int)$userId]);
    $versionId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE games SET published_program_id = ? WHERE id = ?")
        ->execute([$versionId, $gameId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'seed failed: ' . redact_log_value($e->getMessage()) . "\n");
    exit(1);
}

echo "Demo game seeded + tic-tac-toe v1 published.\n";
echo "  game_id:          $gameId\n";
echo "  api_key:          $apiKey   (X-Api-Token)\n";
echo "  api_private_key:  $privateKey   (shown once — X-Api-Private-Token)\n";
echo "\nTry it:\n";
echo "  curl -X POST 'http://localhost/api/player.php?action=register' \\\n";
echo "    -H 'X-Api-Token: $apiKey' -H 'Content-Type: application/json' \\\n";
echo "    -d '{\"name\":\"alice\"}'\n";
