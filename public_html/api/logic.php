<?php
// Game-logic runtime — THE endpoint player devices call.
//
//   POST api/logic.php?action=do        headers: X-Api-Token, X-Player-Token
//        body: { "action": "move", "payload": {x:0,y:0} }
//        → runs the published program's on_player_action["move"] triggers
//          inside a room-locked transaction; reject = whole action vetoed.
//
//   POST api/logic.php?action=message   body: { "message": "chat", "payload": {...} }
//        → fires on_message triggers (server-validated custom messages)
//
//   GET  api/logic.php?action=meta      → describes the published program
//        (trigger table + function signatures) for SDK introspection.
//
// Clients never read/write state directly — they trigger, the server
// verifies and mutates, and results arrive via respond + player_updates.
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
api_headers();

use Mlc\Engine\Program;
use Mlc\Engine\Runtime;

$action = get_param('action') ?? '';
$game = api_auth_game();
/** @var PDO $pdo */

// ====================== META (game token only — SDK/debug introspection) ======================
if ($action === 'meta' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $runtime = new Runtime($pdo);
    $row = $runtime->publishedProgram($game);
    if (!$row) {
        respond_json(['success' => false, 'error' => 'No published program'], 404);
    }
    try {
        $program = Program::fromJson((string)$row['ast']);
    } catch (Throwable $e) {
        error_log('logic meta: corrupt published ast: ' . redact_log_value($e->getMessage()));
        respond_json(['success' => false, 'error' => 'Published program is invalid'], 500);
    }
    respond_json([
        'success' => true,
        'program' => $program->describe() + [
            'id' => (int)$row['program_id'],
            'version' => (int)$row['version'],
            'checksum' => $row['checksum'],
        ],
    ]);
}

// ---------- Everything below acts as a player ----------
$player = api_auth_player($game);
if (!player_is_online($player)) {
    respond_json(['success' => false, 'error' => 'Player is offline — login first'], 403);
}

// ====================== PLAYER ACTION ======================
if ($action === 'do' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_429('logic_action', 120, 60, 'p' . $player['id']);

    $input = json_input();
    $actionName = trim((string)($input['action'] ?? ''));
    if ($actionName === '' || mb_strlen($actionName) > 100
        || !preg_match('/^[a-zA-Z0-9_\-:.]+$/', $actionName)) {
        respond_json(['success' => false, 'error' => 'action name is required (letters, digits, _ - : .)'], 400);
    }
    $payload = $input['payload'] ?? [];
    if (!is_array($payload)) {
        respond_json(['success' => false, 'error' => 'payload must be an object'], 400);
    }

    $membership = player_room_membership($player['id']);
    if (!$membership) {
        respond_json(['success' => false, 'error' => 'Not in a room'], 409);
    }

    $runtime = new Runtime($pdo);
    $result = $runtime->fire(
        $game, $membership['room_id'], $player,
        'on_player_action', $payload, $actionName
    );

    if (!$result['ran']) {
        respond_json(['success' => false, 'error' => 'No published program'], 409);
    }
    if ($result['result'] === 'error') {
        respond_json([
            'success' => false, 'result' => 'error',
            'error' => 'Game logic error',
        ], 500);
    }

    respond_json([
        'success' => $result['result'] === 'ok',
        'result' => $result['result'],
        'reason' => $result['reason'],
        'response' => $result['response'],
        'version' => $result['version'],
    ], $result['result'] === 'rejected' ? 403 : 200);
}

// ====================== MESSAGE (on_message triggers) ======================
if ($action === 'message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_429('logic_message', 60, 60, 'p' . $player['id']);

    $input = json_input();
    $messageName = trim((string)($input['message'] ?? ''));
    if ($messageName === '' || mb_strlen($messageName) > 100
        || !preg_match('/^[a-zA-Z0-9_\-:.]+$/', $messageName)) {
        respond_json(['success' => false, 'error' => 'message name is required'], 400);
    }
    $payload = $input['payload'] ?? [];
    if (!is_array($payload)) {
        respond_json(['success' => false, 'error' => 'payload must be an object'], 400);
    }

    $membership = player_room_membership($player['id']);
    if (!$membership) {
        respond_json(['success' => false, 'error' => 'Not in a room'], 409);
    }

    $runtime = new Runtime($pdo);
    $result = $runtime->fire(
        $game, $membership['room_id'], $player,
        'on_message', $payload, $messageName
    );

    if (!$result['ran']) {
        respond_json(['success' => false, 'error' => 'No published program'], 409);
    }
    respond_json([
        'success' => $result['result'] === 'ok',
        'result' => $result['result'],
        'reason' => $result['reason'],
        'response' => $result['response'],
    ], $result['result'] === 'rejected' ? 403 : ($result['result'] === 'error' ? 500 : 200));
}

respond_json(['success' => false, 'error' => 'Unknown action'], 400);
