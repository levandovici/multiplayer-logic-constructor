<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
send_security_headers();

$title = "Multiplayer Logic Constructor";
$description = "Visual block-programming platform for server-authoritative multiplayer game logic. Design your game's backend like Scratch — we run it, we verify it.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        .hero-gradient {
            background: linear-gradient(135deg, #1e1e2d 0%, #2d1e4a 50%, #1e3a5f 100%);
        }
        .block-chip {
            border-radius: 0.75rem;
            padding: 0.35rem 0.9rem;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
    </style>
</head>
<body class="hero-gradient min-h-screen text-white">
    <main class="max-w-5xl mx-auto px-6 py-24">
        <p class="text-sm uppercase tracking-[0.3em] text-purple-300 mb-4">Multiplayer Logic Constructor</p>
        <h1 class="text-5xl md:text-6xl font-black leading-tight mb-6">
            Your game's backend,<br>
            <span class="text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-cyan-400">built from blocks.</span>
        </h1>
        <p class="text-xl text-gray-300 max-w-2xl mb-10">
            Assemble server-authoritative multiplayer rules visually — like Scratch, but every block
            runs on our servers. Players trigger actions; the platform verifies every move, roll,
            and resource spend. No backend code. No cheating clients.
        </p>

        <div class="flex flex-wrap gap-2 mb-12">
            <span class="block-chip bg-yellow-500/20 text-yellow-300">when player plays card</span>
            <span class="block-chip bg-purple-500/20 text-purple-300">if it's their turn</span>
            <span class="block-chip bg-blue-500/20 text-blue-300">deal 5 cards to player</span>
            <span class="block-chip bg-green-500/20 text-green-300">broadcast to room</span>
            <span class="block-chip bg-red-500/20 text-red-300">else reject move</span>
        </div>

        <div class="flex gap-4">
            <a href="/api/" class="bg-purple-600 hover:bg-purple-500 px-6 py-3 rounded-xl font-semibold transition">API Reference</a>
            <a href="/login.php" class="border border-white/20 hover:bg-white/10 px-6 py-3 rounded-xl font-semibold transition">Developer Login</a>
        </div>
    </main>
</body>
</html>
