<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
send_security_headers();

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

/** @var PDO $pdo */
$stmt = $pdo->prepare("
    SELECT g.id, g.name, g.api_key, g.api_private_key_prefix, g.api_private_key_suffix,
           g.api_private_key_enc, g.published_program_id, g.created_at,
           (SELECT COUNT(*) FROM players p WHERE p.game_id = g.id) AS players,
           (SELECT COUNT(*) FROM rooms r WHERE r.game_id = g.id AND r.is_active = 1) AS active_rooms,
           (SELECT COUNT(*) FROM programs pr WHERE pr.game_id = g.id) AS programs
    FROM games g WHERE g.user_id = ? ORDER BY g.created_at DESC
");
$stmt->execute([(int)$_SESSION['user_id']]);
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cabinet — Multiplayer Logic Constructor</title>
    <?php brand_head_tags('Cabinet — Multiplayer Logic Constructor'); ?>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        code, .mono { font-family: 'Fira Code', monospace; }
        .bg-page { background: linear-gradient(135deg, #1e1e2d 0%, #2d1e4a 50%, #1e3a5f 100%); }
        .glass { background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12); }
    </style>
</head>
<body class="bg-page min-h-screen text-white">
    <header class="border-b border-white/10">
        <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
            <a href="/" class="font-bold flex items-center gap-2"><img src="/assets/logo.svg" alt="" class="w-8 h-8">Multiplayer Logic Constructor</a>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-gray-400"><?= htmlspecialchars($_SESSION['email'] ?? '', ENT_QUOTES) ?></span>
                <form method="POST" action="/php/auth.php" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="logout">
                    <button class="text-gray-300 hover:text-white">Sign out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-10">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold">Your Games</h1>
            <form method="POST" action="/php/games.php" id="create-game" class="flex gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input name="name" required maxlength="100" placeholder="New game name"
                       class="bg-white/10 border border-white/20 rounded-lg px-4 py-2 text-sm w-64 focus:outline-none focus:border-purple-400">
                <button class="bg-purple-600 hover:bg-purple-500 rounded-lg px-5 py-2 text-sm font-semibold transition">Create</button>
            </form>
        </div>

        <div id="flash" class="hidden mb-6 rounded-lg px-4 py-3 text-sm"></div>

        <!-- Start from a template — real programs, playable instantly -->
        <section class="mb-10">
            <h2 class="text-xl font-semibold mb-1">Start from a template</h2>
            <p class="text-sm text-gray-400 mb-4">Pick one, get a playable game + editable blocks in the editor.</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <?php foreach (program_templates() as $slug => $t): ?>
                <button class="tpl-btn glass rounded-xl p-3 text-left hover:border-purple-400/60 transition group" data-slug="<?= $slug ?>">
                    <img src="<?= $t['image'] ?>" alt="<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>"
                         class="w-full aspect-[8/5] object-cover rounded-lg mb-2 border border-white/10">
                    <p class="text-sm font-semibold leading-tight"><?= htmlspecialchars($t['name'], ENT_QUOTES) ?></p>
                    <p class="text-xs text-purple-300"><?= htmlspecialchars($t['genre'], ENT_QUOTES) ?></p>
                </button>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if (!$games): ?>
            <div class="glass rounded-2xl p-12 text-center text-gray-300">
                <p class="text-lg mb-2">No games yet.</p>
                <p class="text-sm">Create one to get an API key, then open the editor and build your game logic from blocks.</p>
            </div>
        <?php else: ?>
            <div class="grid gap-4">
                <?php foreach ($games as $g):
                    $masked = ($g['api_private_key_prefix'] ?: '????') . '…' . ($g['api_private_key_suffix'] ?: '????'); ?>
                <div class="glass rounded-2xl p-6" data-game-id="<?= (int)$g['id'] ?>">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold"><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></h2>
                            <p class="text-sm text-gray-400 mt-1">
                                <?= (int)$g['players'] ?> players · <?= (int)$g['active_rooms'] ?> active rooms ·
                                <?= (int)$g['programs'] ?> program<?= (int)$g['programs'] === 1 ? '' : 's' ?>
                                <?= $g['published_program_id'] ? '· <span class="text-green-400">logic published</span>' : '· <span class="text-yellow-400">no logic published</span>' ?>
                            </p>
                        </div>
                        <a href="/editor.php?game=<?= (int)$g['id'] ?>"
                           class="bg-cyan-600 hover:bg-cyan-500 rounded-lg px-4 py-2 text-sm font-semibold transition shrink-0">Open Editor</a>
                    </div>
                    <div class="mt-4 grid sm:grid-cols-2 gap-3 text-sm">
                        <div class="bg-black/30 rounded-lg px-4 py-3">
                            <p class="text-gray-400 text-xs mb-1">API Key (public — goes in game clients)</p>
                            <code class="text-cyan-300 select-all break-all"><?= htmlspecialchars($g['api_key'], ENT_QUOTES) ?></code>
                        </div>
                        <div class="bg-black/30 rounded-lg px-4 py-3 flex items-center justify-between gap-2">
                            <div>
                                <p class="text-gray-400 text-xs mb-1">Private Key (admin endpoints)</p>
                                <code class="text-purple-300"><?= htmlspecialchars($masked, ENT_QUOTES) ?></code>
                            </div>
                            <div class="flex gap-2 shrink-0">
                                <?php if (!empty($g['api_private_key_enc'])): ?>
                                <button class="reveal-btn text-xs text-gray-300 hover:text-white underline" data-game="<?= (int)$g['id'] ?>">reveal</button>
                                <?php endif; ?>
                                <button class="regen-btn text-xs text-gray-300 hover:text-white underline" data-game="<?= (int)$g['id'] ?>">regenerate</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <script nonce="<?= csp_nonce() ?>">
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const flash = (msg, ok) => {
        const el = document.getElementById('flash');
        el.textContent = msg;
        el.className = 'mb-6 rounded-lg px-4 py-3 text-sm ' + (ok
            ? 'bg-green-500/20 border border-green-400/40 text-green-200'
            : 'bg-red-500/20 border border-red-400/40 text-red-200');
    };
    const post = async (body) => {
        const r = await fetch('/php/games.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body: new URLSearchParams(body),
        });
        return r.json();
    };

    document.getElementById('create-game')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const r = await post({ action: 'create', name: fd.get('name') });
        if (r.success) {
            flash('Game created. Private key (copy now): ' + r.game.api_private_key, true);
            setTimeout(() => location.reload(), 1500);
        } else {
            flash(r.error || 'Failed', false);
        }
    });
    document.querySelectorAll('.reveal-btn').forEach(b => b.addEventListener('click', async () => {
        const r = await post({ action: 'reveal', game_id: b.dataset.game });
        flash(r.success ? 'Private key: ' + r.api_private_key : (r.error || 'Failed'), r.success);
    }));
    document.querySelectorAll('.tpl-btn').forEach(b => b.addEventListener('click', async () => {
        const name = prompt('Name your game:', b.querySelector('p').textContent);
        if (!name) return;
        const r = await post({ action: 'create', name, template: b.dataset.slug });
        if (r.success) {
            flash('Game created — logic already published and playable. Private key (copy now): ' + r.game.api_private_key, true);
            setTimeout(() => location.reload(), 2000);
        } else {
            flash(r.error || 'Failed', false);
        }
    }));
    document.querySelectorAll('.regen-btn').forEach(b => b.addEventListener('click', async () => {
        if (!confirm('Regenerate the private key? The old one stops working immediately.')) return;
        const r = await post({ action: 'regenerate', game_id: b.dataset.game });
        flash(r.success ? 'New private key (copy now): ' + r.api_private_key : (r.error || 'Failed'), r.success);
    }));
    </script>
</body>
</html>
