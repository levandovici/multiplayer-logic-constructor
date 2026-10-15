<?php
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
send_security_headers();

$title = "Multiplayer Logic Constructor — REST API";
$description = "Multiplayer Logic Constructor REST API. Player tokens, rooms, block-program triggers and update polling.";

$endpoints = [
    ['POST', 'api/player.php?action=register',  'Create a player → returns player token'],
    ['POST', 'api/player.php?action=login',     'Re-login by name → returns player token'],
    ['GET',  'api/player.php?action=me',        'Player info + current room'],
    ['POST', 'api/player.php?action=heartbeat', 'Presence + liveness'],
    ['POST', 'api/player.php?action=logout',    'Mark player offline'],
    ['GET',  'api/player.php?action=updates',   'Poll pending player_updates'],
    ['POST', 'api/room.php?action=create',      'Create room → fires on_room_created'],
    ['POST', 'api/room.php?action=join',        'Join room (id/password or join_token) → fires on_player_join'],
    ['POST', 'api/room.php?action=leave',       'Leave room → fires on_player_leave'],
    ['GET',  'api/room.php?action=list',        'List open rooms'],
    ['GET',  'api/room.php?action=info',        'Current room + member list'],
    ['GET',  'api/logic.php?action=meta',       'Published program version + trigger table'],
    ['POST', 'api/logic.php?action=do',         'Fire on_player_action — the gameplay trigger endpoint'],
    ['POST', 'api/logic.php?action=message',    'Fire on_message — server-validated custom messages'],
    ['GET',  'api/state.php',                   'Reconnect snapshot: own player_state + room members'],
    ['GET',  'api/blocks.php',                  'Block/op catalogue — the engine whitelist as data'],
    ['POST', 'api/simulate.php',                'Cabinet-only: dry-run a draft program in a sandbox'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>">
    <?php brand_head_tags($title, $description); ?>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        code, .mono { font-family: 'Fira Code', monospace; }
    </style>
</head>
<body class="bg-[#1e1e2d] text-white min-h-screen flex flex-col">
    <?php site_header('api'); ?>
    <main class="max-w-4xl mx-auto px-6 py-16 flex-1 w-full">
        <h1 class="text-4xl font-bold mb-3">REST API</h1>
        <p class="text-gray-400 mb-8">
            Secrets travel in headers only — never in URLs:
            <code class="text-purple-300">X-Api-Token</code> (game),
            <code class="text-purple-300">X-Api-Private-Token</code> (game admin),
            <code class="text-purple-300">X-Player-Token</code> (player, scoped to the game).
        </p>

        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-white/10 text-gray-400 text-sm">
                    <th class="py-3 pr-4">Method</th>
                    <th class="py-3 pr-4">Endpoint</th>
                    <th class="py-3">Purpose</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($endpoints as [$method, $path, $purpose]): ?>
                <tr class="border-b border-white/5">
                    <td class="py-3 pr-4"><code class="<?= $method === 'GET' ? 'text-green-400' : 'text-yellow-400' ?>"><?= $method ?></code></td>
                    <td class="py-3 pr-4 mono text-sm text-cyan-300"><?= htmlspecialchars($path, ENT_QUOTES) ?></td>
                    <td class="py-3 text-sm text-gray-300"><?= htmlspecialchars($purpose, ENT_QUOTES) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </main>
    <?php site_footer(); ?>
</body>
</html>
