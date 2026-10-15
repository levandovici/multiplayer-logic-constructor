<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
send_security_headers();

$title = "Multiplayer Logic Constructor";
$description = "Visual block-programming platform for server-authoritative multiplayer game logic. Design your game's backend visually — we run it, we verify it.";
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
        <img src="/assets/logo.svg" alt="Multiplayer Logic Constructor" class="w-20 h-20 mb-6 drop-shadow-xl">
        <p class="text-sm uppercase tracking-[0.3em] text-purple-300 mb-4">Multiplayer Logic Constructor</p>
        <h1 class="text-5xl md:text-6xl font-black leading-tight mb-6">
            Your game's backend,<br>
            <span class="text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-cyan-400">built from blocks.</span>
        </h1>
        <p class="text-xl text-gray-300 max-w-2xl mb-10">
            Assemble server-authoritative multiplayer rules from visual blocks — every block
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

        <!-- Genres you can build — artwork: bin/make-marketing.php -->
        <section class="mt-24">
            <h2 class="text-3xl font-bold mb-3">Build any multiplayer genre</h2>
            <p class="text-gray-400 mb-8 max-w-2xl">Your game calls one function — <code class="text-purple-300">Do("action", …)</code> —
                and our servers run your block rules, check the move, and update everyone. If a move is illegal,
                you get <code class="text-purple-300">rejected</code> with the reason. That's the whole API.</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php
                $genres = [
                    ['genre-board.png',   'Board & turn-based', 'Do("move", {x: 1, y: 2})',  'Turn order, win detection, board state — all enforced server-side.'],
                    ['genre-racing.png',  'Racing & laps',      'Do("checkpoint", {id: 7})', 'Checkpoints, lap times and positions verified — no fake finish lines.'],
                    ['genre-clicker.png', 'Idle & economy',     'Do("claim", {})',           'Resources minted and spent on our servers — clients can\'t inflate gold.'],
                    ['genre-quiz.png',    'Quiz & trivia',      'Do("answer", {q: 4, a: 2})','Correctness and timing scored by the room, not by whoever answered.'],
                    ['genre-cards.png',   'Card battlers',      'Do("play_card", {card: 5})','Decks and hands stay secret until a card is legally played.'],
                    ['genre-arena.png',   'Arena & battle',     'Do("use_item", {slot: 1})', 'Zone rules, cooldowns and last-one-standing logic enforced.'],
                ];
                foreach ($genres as [$img, $name, $call, $desc]): ?>
                <div class="bg-white/5 border border-white/10 rounded-2xl overflow-hidden hover:border-purple-400/50 transition">
                    <img src="/assets/marketing/<?= $img ?>" alt="<?= $name ?> example" class="w-full aspect-[8/5] object-cover">
                    <div class="p-5">
                        <h3 class="font-semibold mb-1"><?= $name ?></h3>
                        <code class="text-xs text-cyan-300 mono block mb-2"><?= htmlspecialchars($call, ENT_QUOTES) ?></code>
                        <p class="text-sm text-gray-400"><?= $desc ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Engines/SDKs -->
        <section class="mt-20 mb-8">
            <h2 class="text-3xl font-bold mb-3">Your engine, one API</h2>
            <p class="text-gray-400 mb-6 max-w-2xl">The same five calls in every SDK — register, join a room,
                <code class="text-purple-300">Do()</code>, updates, state. Players can build real multiplayer
                games without writing a single line of server code.</p>
            <div class="flex flex-wrap gap-3">
                <?php foreach (['C# / .NET', 'Unity', 'Unreal Engine (C++ & Blueprints)', 'C++'] as $sdk): ?>
                <span class="block-chip bg-white/10 text-gray-200 border border-white/10"><?= $sdk ?></span>
                <?php endforeach; ?>
            </div>
            <pre class="mono text-sm text-gray-300 bg-black/30 border border-white/10 rounded-xl p-5 mt-6 overflow-x-auto"><code>// the entire client contract — works the same in every SDK
var game = new MlcClient("https://mlc.michitai.com", GAME_API_KEY);
await game.RegisterPlayer("nova");            // get a player token
await game.JoinRoom(roomId);                  // or CreateRoom(...)
var r = await game.Do("move", new { x = 1, y = 2 });
if (r.Result == "rejected") ShowReason(r.Reason);   // "not your turn"
game.OnUpdate += u => ApplyUpdate(u.Type, u.Data);  // server pushes what's visible</code></pre>
        </section>
    </main>
</body>
</html>
