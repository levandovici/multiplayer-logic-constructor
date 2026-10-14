<?php
// GET api/state.php — the player-visible state slice for reconnection.
//
// Deliberately conservative: returns the caller's own player_state, room
// metadata, and the member list — never raw room_state (a program may
// store hidden information there, e.g. deck order). Programs push what
// players should see via send.* updates; this endpoint is the catch-up
// snapshot for a rejoining client.
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_json(['success' => false, 'error' => 'GET only'], 405);
}

$game = api_auth_game();
$player = api_auth_player($game);
/** @var PDO $pdo */

$membership = player_room_membership($player['id']);
if (!$membership) {
    respond_json(['success' => true, 'in_room' => false]);
}

$stmt = $pdo->prepare("SELECT room_id, name, status, max_players FROM rooms WHERE room_id = ?");
$stmt->execute([$membership['room_id']]);
$room = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$room) {
    respond_json(['success' => true, 'in_room' => false]);
}

$pl = $pdo->prepare("
    SELECT player_id, player_name, is_host, is_online, seat
    FROM room_players WHERE room_id = ? ORDER BY seat ASC, joined_at ASC
");
$pl->execute([$room['room_id']]);

$st = $pdo->prepare("SELECT state FROM player_state WHERE room_id = ? AND player_id = ?");
$st->execute([$room['room_id'], $player['id']]);
$myState = $st->fetchColumn();
$myState = $myState === false ? [] : (json_decode((string)$myState, true) ?: []);

respond_json([
    'success' => true,
    'in_room' => true,
    'room' => [
        'room_id' => $room['room_id'],
        'name' => $room['name'],
        'status' => $room['status'],
        'max_players' => (int)$room['max_players'],
        'members' => array_map(fn($m) => [
            'id' => (int)$m['player_id'],
            'name' => $m['player_name'],
            'is_host' => (bool)$m['is_host'],
            'is_online' => (bool)$m['is_online'],
            'seat' => $m['seat'] !== null ? (int)$m['seat'] : null,
        ], $pl->fetchAll(PDO::FETCH_ASSOC)),
    ],
    'me' => [
        'id' => (int)$player['id'],
        'name' => $player['name'],
        'is_host' => (bool)$membership['is_host'],
        'seat' => $membership['seat'] !== null ? (int)$membership['seat'] : null,
        'state' => $myState,
    ],
]);
