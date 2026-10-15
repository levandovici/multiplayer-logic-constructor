<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
send_security_headers();

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$gameId = (int)(get_param('game') ?? 0);
/** @var PDO $pdo */
$stmt = $pdo->prepare("SELECT * FROM games WHERE id = ? AND user_id = ?");
$stmt->execute([$gameId, (int)$_SESSION['user_id']]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$game) {
    header('Location: /cabinet.php');
    exit;
}

// Load (or lazily create) the single program per game — name 'main'.
$stmt = $pdo->prepare("SELECT * FROM programs WHERE game_id = ? ORDER BY id LIMIT 1");
$stmt->execute([$gameId]);
$program = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$program) {
    $pdo->prepare("INSERT INTO programs (game_id, name) VALUES (?, 'main')")->execute([$gameId]);
    $program = ['id' => (int)$pdo->lastInsertId(), 'name' => 'main', 'draft_workspace' => null, 'draft_ast' => null];
}

$versions = $pdo->prepare("
    SELECT id, version, published_at, checksum FROM program_versions
    WHERE program_id = ? ORDER BY version DESC LIMIT 20
");
$versions->execute([(int)$program['id']]);
$versionList = $versions->fetchAll(PDO::FETCH_ASSOC);

// Variable defaults live inside the AST (variables key) — no separate column.
$initialVars = null;
if ($program['draft_ast']) {
    $decoded = json_decode((string)$program['draft_ast'], true);
    if (is_array($decoded) && isset($decoded['variables']) && is_array($decoded['variables'])) {
        $initialVars = $decoded['variables'];
    }
}
$workspaceJson = $program['draft_workspace'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor — <?= htmlspecialchars($game['name'], ENT_QUOTES) ?> — Multiplayer Logic Constructor</title>
    <?php brand_head_tags('Editor — Multiplayer Logic Constructor'); ?>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://unpkg.com/blockly@10.4.3/blockly.min.js"></script>
    <script src="/js/mlc-blocks.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        code, .mono { font-family: 'Fira Code', monospace; }
        #blocklyDiv { position: absolute; inset: 0; }
        .blocklyToolbox { background: rgba(30,30,45,0.95); }
    </style>
</head>
<body class="bg-[#1e1e2d] text-white h-screen flex flex-col overflow-hidden">
    <header class="border-b border-white/10 shrink-0">
        <div class="px-4 h-14 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="/" title="Multiplayer Logic Constructor"><img src="/assets/logo.svg" alt="" class="w-8 h-8"></a>
                <a href="/cabinet.php" class="text-gray-400 hover:text-white text-sm">← Cabinet</a>
                <h1 class="font-semibold"><?= htmlspecialchars($game['name'], ENT_QUOTES) ?></h1>
                <span class="text-xs text-gray-500 mono">program: <?= htmlspecialchars($program['name'] ?? 'main', ENT_QUOTES) ?></span>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <span id="status" class="text-gray-400 mr-2"></span>
                <?php if ($versionList): ?>
                <select id="versions" class="bg-white/10 rounded px-2 py-1.5 text-xs text-gray-300">
                    <option value="">published versions…</option>
                    <?php foreach ($versionList as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" <?= (int)$game['published_program_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                        v<?= (int)$v['version'] ?> — <?= htmlspecialchars((string)$v['published_at'], ENT_QUOTES) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button id="btn-rollback" class="bg-white/10 hover:bg-white/20 rounded px-3 py-1.5">Rollback to selected</button>
                <?php endif; ?>
                <button id="btn-vars" class="bg-white/10 hover:bg-white/20 rounded px-3 py-1.5">Variables</button>
                <button id="btn-save" class="bg-white/10 hover:bg-white/20 rounded px-3 py-1.5">Save draft</button>
                <button id="btn-validate" class="bg-yellow-600/70 hover:bg-yellow-600 rounded px-3 py-1.5">Validate</button>
                <button id="btn-sim" class="bg-cyan-700 hover:bg-cyan-600 rounded px-3 py-1.5">▶ Simulate</button>
                <button id="btn-publish" class="bg-green-600 hover:bg-green-500 rounded px-4 py-1.5 font-semibold">Publish</button>
            </div>
        </div>
    </header>

    <div class="flex-1 relative">
        <div id="blocklyDiv"></div>
    </div>

    <div id="vars-modal" class="hidden fixed inset-0 bg-black/60 flex items-center justify-center z-50">
        <div class="bg-[#252538] rounded-xl p-6 w-[560px] max-h-[80vh] overflow-auto border border-white/10">
            <h2 class="font-semibold mb-3">Variable defaults (JSON)</h2>
            <p class="text-xs text-gray-400 mb-2">
                Declared per scope — merged under stored state, so every
                declared var reads a default before the first write.
            </p>
            <textarea id="vars-json" rows="10" spellcheck="false"
                class="w-full mono text-xs bg-black/40 border border-white/15 rounded p-3 focus:outline-none focus:border-purple-400"
            >{"player": {}, "room": {}, "game": {}}</textarea>
            <div class="flex justify-end gap-2 mt-4 text-sm">
                <button id="vars-close" class="bg-white/10 hover:bg-white/20 rounded px-4 py-1.5">Close</button>
            </div>
        </div>
    </div>

    <div id="sim-modal" class="hidden fixed inset-0 bg-black/60 flex items-center justify-center z-50">
        <div class="bg-[#252538] rounded-xl p-6 w-[680px] max-h-[85vh] overflow-auto border border-white/10">
            <h2 class="font-semibold mb-3">Simulate — sandbox run, no real state touched</h2>
            <div class="grid grid-cols-2 gap-3 text-sm mb-3">
                <label class="block">Event
                    <select id="sim-event" class="mt-1 w-full bg-black/40 border border-white/15 rounded px-2 py-1.5">
                        <option value="on_player_action">on_player_action</option>
                        <option value="on_message">on_message</option>
                        <option value="on_timer">on_timer</option>
                        <option value="on_player_join">on_player_join</option>
                        <option value="on_player_leave">on_player_leave</option>
                        <option value="on_room_created">on_room_created</option>
                    </select>
                </label>
                <label class="block">Key (action/timer/message name)
                    <input id="sim-key" value="move" class="mt-1 w-full bg-black/40 border border-white/15 rounded px-2 py-1.5 mono text-xs">
                </label>
            </div>
            <label class="block text-sm mb-1">Payload (JSON)</label>
            <textarea id="sim-payload" rows="3" spellcheck="false"
                class="w-full mono text-xs bg-black/40 border border-white/15 rounded p-2">{"x":0,"y":0}</textarea>
            <label class="block text-sm mt-3 mb-1">Members (JSON — default: alice+bob)</label>
            <textarea id="sim-members" rows="3" spellcheck="false"
                class="w-full mono text-xs bg-black/40 border border-white/15 rounded p-2">[{"id":1,"name":"alice","seat":0,"is_host":1},{"id":2,"name":"bob","seat":1,"is_host":0}]</textarea>
            <div class="flex items-center gap-3 mt-3 text-sm">
                <label>Acting player id
                    <input id="sim-acting" type="number" value="1" class="ml-1 w-20 bg-black/40 border border-white/15 rounded px-2 py-1 mono text-xs">
                </label>
                <button id="sim-run" class="ml-auto bg-cyan-600 hover:bg-cyan-500 rounded px-5 py-1.5 font-semibold">Run</button>
            </div>
            <pre id="sim-out" class="mono text-xs bg-black/40 border border-white/15 rounded p-3 mt-4 whitespace-pre-wrap max-h-64 overflow-auto">run a simulation to see results</pre>
            <div class="flex justify-end mt-4 text-sm">
                <button id="sim-close" class="bg-white/10 hover:bg-white/20 rounded px-4 py-1.5">Close</button>
            </div>
        </div>
    </div>

    <script nonce="<?= csp_nonce() ?>">
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const PROGRAM_ID = <?= (int)$program['id'] ?>;
    const GAME_ID = <?= (int)$gameId ?>;
    const initialVars = <?= json_encode($initialVars) ?>;

    Blockly.defineBlocksWithJsonArray(MLC.BLOCKS);
    const ws = Blockly.inject('blocklyDiv', {
        toolbox: MLC.TOOLBOX,
        grid: { spacing: 24, length: 3, colour: '#333', snap: true },
        zoom: { controls: true, wheel: true, startScale: 0.85 },
        trashcan: true,
        theme: Blockly.Themes.Classic,
    });

    const savedRaw = <?= json_encode($workspaceJson === null ? null : (string)$workspaceJson) ?>;
    if (savedRaw) {
        try { Blockly.serialization.workspaces.load(JSON.parse(savedRaw), ws); }
        catch (e) { console.error('load failed', e); }
    }
    const varsEl = document.getElementById('vars-json');
    if (initialVars) { varsEl.value = JSON.stringify(initialVars, null, 2); }

    const status = (msg, ok) => {
        const el = document.getElementById('status');
        el.textContent = msg;
        el.className = 'mr-2 ' + (ok === null ? 'text-gray-400' : ok ? 'text-green-400' : 'text-red-400');
    };

    function currentAst() {
        let vars;
        try { vars = JSON.parse(varsEl.value); }
        catch (e) { status('Variables JSON is invalid: ' + e.message, false); return null; }
        return MLC.generateAst(ws, vars);
    }

    async function post(action, extra) {
        const r = await fetch('/php/programs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body: new URLSearchParams(Object.assign({
                action, program_id: PROGRAM_ID, game_id: GAME_ID,
            }, extra || {})),
        });
        return r.json();
    }

    document.getElementById('btn-vars').addEventListener('click', () =>
        document.getElementById('vars-modal').classList.remove('hidden'));
    document.getElementById('vars-close').addEventListener('click', () =>
        document.getElementById('vars-modal').classList.add('hidden'));

    document.getElementById('btn-save').addEventListener('click', async () => {
        const ast = currentAst();
        if (!ast) { return; }
        const r = await post('save', {
            workspace: JSON.stringify(Blockly.serialization.workspaces.save(ws)),
            ast: JSON.stringify(ast),
            vars: varsEl.value,
        });
        status(r.success ? 'Draft saved' : (r.error || 'Save failed'), r.success);
    });

    document.getElementById('btn-validate').addEventListener('click', async () => {
        const ast = currentAst();
        if (!ast) { return; }
        status('validating…', null);
        const r = await post('validate', { ast: JSON.stringify(ast) });
        if (r.success) {
            status('Valid — ' + r.summary.triggers + ' trigger(s), ' + r.summary.functions + ' function(s)', true);
        } else {
            status(r.error || 'Invalid', false);
        }
    });

    document.getElementById('btn-rollback')?.addEventListener('click', async () => {
        const vid = document.getElementById('versions').value;
        if (!vid) { status('Pick a version first', false); return; }
        if (!confirm('Point the runtime at that version? Live traffic switches immediately.')) return;
        const r = await post('rollback', { version_id: vid });
        status(r.success ? 'Rolled back to v' + r.version : (r.error || 'Rollback failed'), r.success);
    });

    document.getElementById('btn-sim').addEventListener('click', () =>
        document.getElementById('sim-modal').classList.remove('hidden'));
    document.getElementById('sim-close').addEventListener('click', () =>
        document.getElementById('sim-modal').classList.add('hidden'));

    document.getElementById('sim-run').addEventListener('click', async () => {
        const ast = currentAst();
        if (!ast) { return; }
        let payload, members;
        try { payload = JSON.parse(document.getElementById('sim-payload').value || '{}'); }
        catch (e) { status('Payload JSON invalid', false); return; }
        try { members = JSON.parse(document.getElementById('sim-members').value || '[]'); }
        catch (e) { status('Members JSON invalid', false); return; }
        const out = document.getElementById('sim-out');
        out.textContent = 'running…';
        const r = await fetch('/api/simulate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({
                ast, payload, members,
                event: document.getElementById('sim-event').value,
                key: document.getElementById('sim-key').value || null,
                acting: parseInt(document.getElementById('sim-acting').value, 10) || 1,
            }),
        }).then((x) => x.json());
        if (!r.success) { out.textContent = 'error: ' + (r.error || 'failed'); return; }
        out.textContent = JSON.stringify({
            result: r.result, error: r.error, response: r.response,
            steps: r.steps, duration_ms: r.duration_ms,
            state: r.state, updates: r.updates, scheduled: r.scheduled,
            kicked: r.kicked, room_status: r.room_status, log: r.log,
        }, null, 2);
    });

    document.getElementById('btn-publish').addEventListener('click', async () => {
        const ast = currentAst();
        if (!ast) { return; }
        status('publishing…', null);
        const r = await post('publish', {
            workspace: JSON.stringify(Blockly.serialization.workspaces.save(ws)),
            ast: JSON.stringify(ast),
            vars: varsEl.value,
        });
        status(r.success ? 'Published v' + r.version : (r.error || 'Publish failed'), r.success);
    });
    </script>
</body>
</html>
