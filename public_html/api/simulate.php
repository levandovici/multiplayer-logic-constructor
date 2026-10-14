<?php
// Cabinet simulator — dry-run a draft program against a sandbox room.
// Session-auth (NOT game API tokens): this is a developer tool, it never
// touches production tables — everything runs on an in-memory store.
//
//   POST api/simulate.php  (cabinet session + CSRF)
//   body: {
//     ast:      {...}        canonical program (from the editor's draft)
//     event:    "on_player_action" | "on_player_join" | ...
//     key:      "move"       action/timer/message name for keyed events
//     payload:  {...}        the client's action args
//     members:  [{id,name,seat,is_host}]   fake room roster
//     acting:   10           which member id acts (default: first)
//     state:    {room:{},player:{id:{}},game:{}}   seed state
//   }
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
header('Content-Type: application/json');

use Mlc\Engine\ArrayStateStore;
use Mlc\Engine\BudgetExhausted;
use Mlc\Engine\Context;
use Mlc\Engine\EngineError;
use Mlc\Engine\Executor;
use Mlc\Engine\Program;
use Mlc\Engine\Reject;

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in']);
    exit;
}
require_csrf_json();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$in = json_input();
$astJson = $in['ast'] ?? null;
$ast = is_string($astJson) ? json_decode($astJson, true) : $astJson;
if (!is_array($ast)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ast (object or JSON string) is required']);
    exit;
}

try {
    $program = Program::load($ast);
} catch (EngineError $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Program invalid: ' . $e->getMessage()]);
    exit;
}

$event = (string)($in['event'] ?? 'on_player_action');
if (!\Mlc\Engine\Ops::isEvent($event)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown event']);
    exit;
}
$key = isset($in['key']) ? (string)$in['key'] : null;
$payload = is_array($in['payload'] ?? null) ? $in['payload'] : [];

// ---------- sandbox world ----------
$store = new ArrayStateStore();

$members = is_array($in['members'] ?? null) ? $in['members'] : [
    ['id' => 1, 'name' => 'alice', 'seat' => 0, 'is_host' => 1],
    ['id' => 2, 'name' => 'bob', 'seat' => 1, 'is_host' => 0],
];
$norm = [];
foreach ($members as $m) {
    if (!is_array($m) || !isset($m['id'])) {
        continue;
    }
    $norm[] = [
        'room_id' => 'sandbox',
        'player_id' => (int)$m['id'],
        'player_name' => (string)($m['name'] ?? ('p' . (int)$m['id'])),
        'is_host' => !empty($m['is_host']) ? 1 : 0,
        'is_online' => 1,
        'seat' => isset($m['seat']) ? (int)$m['seat'] : null,
    ];
}
$store->members = $norm;

$actingId = (int)($in['acting'] ?? ($norm[0]['player_id'] ?? 1));
$actingRow = ['id' => $actingId, 'game_id' => 1, 'name' => 'sim' . $actingId];
foreach ($norm as $m) {
    if ((int)$m['player_id'] === $actingId) {
        $actingRow['name'] = $m['player_name'];
    }
}

$seed = is_array($in['state'] ?? null) ? $in['state'] : [];
$store->roomStates['sandbox'] = is_array($seed['room'] ?? null) ? $seed['room'] : [];
$store->gameStates[1] = is_array($seed['game'] ?? null) ? $seed['game'] : [];
foreach (($seed['player'] ?? []) as $pid => $st) {
    if (is_array($st)) {
        $store->playerStates['sandbox:' . (int)$pid] = $st;
    }
}

$game = ['id' => 1, 'name' => 'simulation', 'published_program_id' => 0];
$room = ['room_id' => 'sandbox', 'game_id' => 1, 'name' => 'Sandbox', 'status' => 'playing'];

// ---------- run ----------
$ctx = new Context($store, $game, $room, $actingRow, $payload, $event, $key, $program->defaultsAll());
$exec = new Executor($program, $ctx);
$start = microtime(true);
$outcome = 'ok';
$errMsg = null;
try {
    $exec->fire();
    $ctx->flush();
} catch (Reject $r) {
    $outcome = 'rejected';
    $errMsg = $r->reason;
} catch (BudgetExhausted $e) {
    $outcome = 'budget_exhausted';
    $errMsg = $e->getMessage();
} catch (EngineError $e) {
    $outcome = 'error';
    $errMsg = $e->getMessage();
} catch (Throwable $e) {
    $outcome = 'error';
    $errMsg = 'internal: ' . $e->getMessage();
    error_log('simulate error: ' . redact_log_value($e->getMessage()));
}

echo json_encode([
    'success' => true,
    'result' => $outcome,
    'error' => $errMsg,
    'response' => $ctx->response,
    'steps' => $exec->stepsUsed(),
    'duration_ms' => (int)round((microtime(true) - $start) * 1000),
    'state' => [
        'room' => $store->roomStates['sandbox'] ?? [],
        'player' => $store->playerStates,
        'game' => $store->gameStates[1] ?? [],
    ],
    'updates' => $store->sentUpdates,
    'scheduled' => $store->events,
    'kicked' => $store->kicked,
    'room_status' => $store->roomStatuses['sandbox'] ?? $room['status'],
    'log' => $ctx->logLines,
]);
