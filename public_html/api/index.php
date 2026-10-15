<?php
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
send_security_headers();

$title = "Multiplayer Logic Constructor — API & SDKs";
$description = "Multiplayer Logic Constructor REST API and SDKs for C#/.NET, Unity, Unreal Engine and C++. Players trigger actions; the server decides.";

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

$sections = [
    ['rest',   'REST API'],
    ['dotnet', 'C# / .NET'],
    ['unity',  'Unity'],
    ['unreal', 'Unreal Engine'],
    ['cpp',    'C++'],
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
        section { scroll-margin-top: 5.5rem; }
    </style>
</head>
<body class="bg-[#1e1e2d] text-white min-h-screen flex flex-col">
    <?php site_header('api'); ?>

    <!-- Section sub-nav -->
    <nav class="border-b border-white/10 bg-[#1a1a28]/90 backdrop-blur sticky top-16 z-40">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 flex gap-1 sm:gap-2 overflow-x-auto py-2.5 text-sm">
            <?php foreach ($sections as [$anchor, $label]): ?>
            <a href="#<?= $anchor ?>" class="whitespace-nowrap text-gray-400 hover:text-white hover:bg-white/10 rounded-lg px-3 py-1.5 transition"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </nav>

    <main class="max-w-4xl mx-auto px-5 sm:px-6 py-12 sm:py-16 flex-1 w-full">
        <h1 class="text-3xl sm:text-4xl font-bold mb-3">API &amp; SDKs</h1>
        <p class="text-gray-400 mb-3">
            One mental model, every platform: <em class="text-gray-200">you ask, the server decides.</em>
            Players trigger named actions — your block rules run on our servers, check the move,
            update state, and reply <code class="text-green-300">ok</code> or
            <code class="text-yellow-300">rejected</code> with a reason.
        </p>
        <p class="text-gray-400 mb-10">
            Secrets travel in headers only — never in URLs:
            <code class="text-purple-300">X-Api-Token</code> (game),
            <code class="text-purple-300">X-Api-Private-Token</code> (game admin),
            <code class="text-purple-300">X-Player-Token</code> (player, scoped to the game).
        </p>

        <!-- ============ REST API ============ -->
        <section id="rest" class="mb-16">
            <h2 class="text-2xl font-bold mb-2">REST API</h2>
            <p class="text-gray-400 text-sm mb-6">Plain JSON over HTTPS — every SDK below is a thin wrapper over these endpoints.</p>
            <div class="overflow-x-auto -mx-5 px-5 sm:mx-0 sm:px-0">
            <table class="w-full min-w-[560px] text-left border-collapse">
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
            </div>

            <h3 class="font-semibold mt-8 mb-2">Result shape</h3>
            <pre class="mono text-sm text-gray-300 bg-black/30 border border-white/10 rounded-xl p-5 overflow-x-auto"><code>{
  "success": true,
  "result": "ok",            // or "rejected" — a NORMAL answer, not an error
  "reason": null,            // rejection reason, e.g. "not your turn"
  "response": { … },         // whatever your respond block set
  "response_json": "{ … }",  // same data as a string — for Unity / Unreal
  "version": 3               // published program version that ran
}</code></pre>
            <p class="text-sm text-gray-500 mt-2">
                Free-form fields always ship twice: parsed (<code>data</code>, <code>response</code>,
                <code>state</code>) and as a raw string (<code>data_json</code>, <code>response_json</code>,
                <code>state_json</code>). Requests accept <code>payload</code> or a <code>payload_json</code> string.
            </p>
        </section>

        <!-- ============ C# / .NET ============ -->
        <section id="dotnet" class="mb-16">
            <h2 class="text-2xl font-bold mb-2">C# / .NET</h2>
            <p class="text-gray-400 text-sm mb-4">
                <code class="text-cyan-300">MlcClient</code> — <code>HttpClient</code> + <code>System.Text.Json</code>,
                async/await, netstandard-compatible (works inside Unity too).
            </p>
            <pre class="mono text-sm text-gray-300 bg-black/30 border border-white/10 rounded-xl p-5 overflow-x-auto"><code>var game = new MlcClient("https://mlc.michitai.com", "GAME_API_KEY");

await game.RegisterPlayer("nova");      // → player token (SDK stores it)
await game.JoinRoom("room-id");         // or CreateRoom("lobby", max: 4)

var r = await game.Do("move", new { x = 1, y = 2 });
if (r.Result == "rejected")             // e.g. "not your turn"
    ShowReason(r.Reason);

game.OnUpdate += u =&gt; ApplyUpdate(u.Type, u.Data);   // "Bob moved", "you win"…</code></pre>
        </section>

        <!-- ============ Unity ============ -->
        <section id="unity" class="mb-16">
            <h2 class="text-2xl font-bold mb-2">Unity</h2>
            <p class="text-gray-400 text-sm mb-4">
                Same client core + <code class="text-cyan-300">MlcClientBehaviour : MonoBehaviour</code> —
                polls updates on an interval, raises <code>UnityEvent</code>s you wire in the inspector.
                Unity's <code>JsonUtility</code> can't handle free-form JSON, so payloads go through the
                <code class="text-purple-300">*_json</code> string fields.
            </p>
            <pre class="mono text-sm text-gray-300 bg-black/30 border border-white/10 rounded-xl p-5 overflow-x-auto"><code>public class GameController : MonoBehaviour {
    public MlcClientBehaviour client;          // drag onto the GameObject

    void Start() {
        client.OnUpdate.AddListener(u =&gt; {
            var m = JsonUtility.FromJson&lt;MoveData&gt;(u.DataJson); // typed parse
            ApplyUpdate(u.Type, m);
        });
    }

    public void OnCellClicked(int x, int y) {
        client.Do("move", new MoveArgs { x = x, y = y });
    }
}

[Serializable] class MoveArgs { public int x; public int y; }
[Serializable] class MoveData { public int x; public int y; public string player; }</code></pre>
        </section>

        <!-- ============ Unreal ============ -->
        <section id="unreal" class="mb-16">
            <h2 class="text-2xl font-bold mb-2">Unreal Engine</h2>
            <p class="text-gray-400 text-sm mb-4">
                <code class="text-cyan-300">UMlcClient</code> — Blueprint-callable UObject over
                <code>IHttpRequest</code>; updates via a ticker poll + dynamic multicast delegates.
                Pass payload as a raw JSON <code>FString</code> (<code>payload_json</code>).
            </p>
            <pre class="mono text-sm text-gray-300 bg-black/30 border border-white/10 rounded-xl p-5 overflow-x-auto"><code>UMlcClient* Game = NewObject&lt;UMlcClient&gt;();
Game-&gt;Init(TEXT("https://mlc.michitai.com"), TEXT("GAME_API_KEY"));

Game-&gt;OnMlcUpdate.AddDynamic(this, &amp;AMyGameMode::HandleUpdate);
Game-&gt;OnMlcRejected.AddDynamic(this, &amp;AMyGameMode::ShowReason);

Game-&gt;RegisterPlayer(TEXT("nova"));
Game-&gt;JoinRoom(TEXT("room-id"));
Game-&gt;Do(TEXT("move"), TEXT("{\"x\":1,\"y\":2}"));   // raw JSON string payload</code></pre>
            <p class="text-sm text-gray-500 mt-2">All of this is Blueprint-callable — game designers can wire it without C++.</p>
        </section>

        <!-- ============ C++ ============ -->
        <section id="cpp" class="mb-8">
            <h2 class="text-2xl font-bold mb-2">C++</h2>
            <p class="text-gray-400 text-sm mb-4">
                <code class="text-cyan-300">mlc::Client</code> — libcurl/WinHTTP + <code>nlohmann::json</code>,
                no engine dependency. Updates via <code>std::function</code> callback.
            </p>
            <pre class="mono text-sm text-gray-300 bg-black/30 border border-white/10 rounded-xl p-5 overflow-x-auto"><code>mlc::Client game("https://mlc.michitai.com", "GAME_API_KEY");

game.registerPlayer("nova");
game.joinRoom("room-id");

game.onUpdate([](const mlc::Update&amp; u) {
    applyUpdate(u.type, u.data);        // nlohmann::json — free-form
});

auto r = game.do_("move", {{"x", 1}, {"y", 2}});
if (r.result == "rejected") showReason(r.reason);</code></pre>
        </section>
    </main>
    <?php site_footer(); ?>
</body>
</html>
