<?php
// Room lifecycle: create, join, leave, list, info.
// Each mutating endpoint performs the DB operation then fires the matching
// lifecycle trigger via fire_lifecycle_event() (no-op until the engine lands).
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
api_headers();

$action = get_param('action') ?? '';
$game = api_auth_game();
$input = json_input();
/** @var PDO $pdo */

// ====================== LIST (game token only — lobby browser) ======================
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("
        SELECT r.room_id, r.name, r.max_players, r.status, r.created_at,
               (r.password IS NOT NULL) AS has_password,
               (SELECT COUNT(*) FROM room_players rp WHERE rp.room_id = r.room_id AND rp.is_online = 1) AS players_online
        FROM rooms r
        WHERE r.game_id = ? AND r.is_active = 1
        ORDER BY r.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$game['id']]);
    respond_json(['success' => true, 'rooms' => array_map(fn($r) => [
        'room_id' => $r['room_id'],
        'name' => $r['name'],
        'status' => $r['status'],
        'max_players' => (int)$r['max_players'],
        'players_online' => (int)$r['players_online'],
        'has_password' => (bool)$r['has_password'],
        'created_at' => isoUtc($r['created_at']),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC))]);
}

// ---------- Everything below requires a player token ----------
$player = api_auth_player($game);
if (!player_is_online($player)) {
    respond_json(['success' => false, 'error' => 'Player is offline — login first'], 403);
}

// ====================== CREATE ======================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_429('room_create', 20, 300, 'p' . $player['id']);

    if (player_room_membership($player['id'])) {
        respond_json(['success' => false, 'error' => 'Already in a room — leave first'], 409);
    }

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 255) {
        respond_json(['success' => false, 'error' => 'Room name is required (max 255 chars)'], 400);
    }
    $maxPlayers = (int)($input['max_players'] ?? 6);
    if ($maxPlayers < 2 || $maxPlayers > 64) {
        respond_json(['success' => false, 'error' => 'max_players must be 2–64'], 400);
    }
    $password = (string)($input['password'] ?? '');
    $joinToken = bin2hex(random_bytes(8));

    $roomId = generate_uuid();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO rooms (room_id, game_id, name, host_player_id, password, join_token, max_players)
            VALUES (:rid, :gid, :name, :host, :pass, :jt, :max)
        ")->execute([
            'rid' => $roomId, 'gid' => $game['id'], 'name' => $name,
            'host' => $player['id'],
            'pass' => $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null,
            'jt' => $joinToken, 'max' => $maxPlayers,
        ]);
        $pdo->prepare("
            INSERT INTO room_players (player_id, room_id, game_id, player_name, is_host, is_online, seat)
            VALUES (:pid, :rid, :gid, :pname, 1, 1, 0)
        ")->execute([
            'pid' => $player['id'], 'rid' => $roomId,
            'gid' => $game['id'], 'pname' => $player['name'],
        ]);
        $lr = fire_lifecycle_event('on_room_created', [
            'game' => $game, 'room_id' => $roomId, 'player' => $player,
        ]);
        if (lifecycle_vetoed($lr)) {
            exit; // respond_json already replied; engine rolled back the txn
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('room create error: ' . redact_log_value($e->getMessage()));
        respond_json(['success' => false, 'error' => 'Database error'], 500);
    }

    respond_json([
        'success' => true,
        'room' => [
            'room_id' => $roomId,
            'name' => $name,
            'max_players' => $maxPlayers,
            'join_token' => $joinToken,
            'is_host' => true,
        ],
    ]);
}

// ====================== JOIN ======================
if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_429('room_join', 30, 300, 'p' . $player['id']);

    if (player_room_membership($player['id'])) {
        respond_json(['success' => false, 'error' => 'Already in a room — leave first'], 409);
    }

    $roomId = (string)($input['room_id'] ?? '');
    $joinToken = (string)($input['join_token'] ?? '');
    $password = (string)($input['password'] ?? '');

    // Resolve room by id, or by invite token (token bypasses password).
    $byToken = $joinToken !== '';
    $stmt = $byToken
        ? $pdo->prepare("SELECT * FROM rooms WHERE join_token = ? AND game_id = ? AND is_active = 1")
        : $pdo->prepare("SELECT * FROM rooms WHERE room_id = ? AND game_id = ? AND is_active = 1");
    $stmt->execute([$byToken ? $joinToken : $roomId, $game['id']]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        respond_json(['success' => false, 'error' => 'Room not found'], 404);
    }

    if (!$byToken && $room['password'] !== null && !password_verify($password, $room['password'])) {
        respond_json(['success' => false, 'error' => 'Wrong room password'], 403);
    }

    $pdo->beginTransaction();
    try {
        // Serialized capacity check inside the txn; seat = MAX+1 so a leave
        // can't free a number the next join would re-assign (collision would
        // corrupt ctx.player_seat for two members).
        $cnt = $pdo->prepare("SELECT COUNT(*), COALESCE(MAX(seat), -1) FROM room_players WHERE room_id = ? FOR UPDATE");
        $cnt->execute([$room['room_id']]);
        [$memberCount, $maxSeat] = array_map('intval', $cnt->fetch(PDO::FETCH_NUM));
        $seat = $maxSeat + 1;
        if ($memberCount >= (int)$room['max_players']) {
            $pdo->rollBack();
            respond_json(['success' => false, 'error' => 'Room is full'], 409);
        }

        $pdo->prepare("
            INSERT INTO room_players (player_id, room_id, game_id, player_name, is_host, is_online, seat)
            VALUES (:pid, :rid, :gid, :pname, 0, 1, :seat)
        ")->execute([
            'pid' => $player['id'], 'rid' => $room['room_id'],
            'gid' => $game['id'], 'pname' => $player['name'], 'seat' => $seat,
        ]);
        $pdo->prepare("UPDATE rooms SET last_activity = NOW() WHERE room_id = ?")
            ->execute([$room['room_id']]);
        $lr = fire_lifecycle_event('on_player_join', [
            'game' => $game, 'room_id' => $room['room_id'], 'player' => $player,
        ]);
        if (lifecycle_vetoed($lr)) {
            exit;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
            respond_json(['success' => false, 'error' => 'Already in a room — leave first'], 409);
        }
        error_log('room join error: ' . redact_log_value($e->getMessage()));
        respond_json(['success' => false, 'error' => 'Database error'], 500);
    }

    respond_json([
        'success' => true,
        'room' => [
            'room_id' => $room['room_id'],
            'name' => $room['name'],
            'status' => $room['status'],
            'is_host' => false,
        ],
    ]);
}

// ====================== LEAVE ======================
if ($action === 'leave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $membership = player_room_membership($player['id']);
    if (!$membership) {
        respond_json(['success' => false, 'error' => 'Not in a room'], 409);
    }
    $roomId = $membership['room_id'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM room_players WHERE player_id = ?")->execute([$player['id']]);

        $remaining = $pdo->prepare("
            SELECT player_id FROM room_players WHERE room_id = ? ORDER BY joined_at ASC
        ");
        $remaining->execute([$roomId]);
        $left = $remaining->fetchAll(PDO::FETCH_COLUMN);

        if ($left === []) {
            // Empty room → deactivate.
            $pdo->prepare("UPDATE rooms SET is_active = 0, host_player_id = NULL WHERE room_id = ?")
                ->execute([$roomId]);
        } elseif ($membership['is_host']) {
            // Host migration: earliest-joined player becomes host.
            $newHost = (int)$left[0];
            $pdo->prepare("UPDATE room_players SET is_host = 1 WHERE player_id = ?")
                ->execute([$newHost]);
            $pdo->prepare("UPDATE rooms SET host_player_id = ? WHERE room_id = ?")
                ->execute([$newHost, $roomId]);
        }
        $pdo->prepare("UPDATE rooms SET last_activity = NOW() WHERE room_id = ?")->execute([$roomId]);
        $lr = fire_lifecycle_event('on_player_leave', [
            'game' => $game, 'room_id' => $roomId, 'player' => $player,
        ]);
        if (lifecycle_vetoed($lr)) {
            exit;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('room leave error: ' . redact_log_value($e->getMessage()));
        respond_json(['success' => false, 'error' => 'Database error'], 500);
    }

    respond_json(['success' => true]);
}

// ====================== INFO ======================
if ($action === 'info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $membership = player_room_membership($player['id']);
    if (!$membership) {
        respond_json(['success' => false, 'error' => 'Not in a room'], 409);
    }
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = ?");
    $stmt->execute([$membership['room_id']]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    $pl = $pdo->prepare("
        SELECT player_id, player_name, is_host, is_online, seat, joined_at
        FROM room_players WHERE room_id = ? ORDER BY seat ASC, joined_at ASC
    ");
    $pl->execute([$membership['room_id']]);

    respond_json([
        'success' => true,
        'room' => [
            'room_id' => $room['room_id'],
            'name' => $room['name'],
            'status' => $room['status'],
            'max_players' => (int)$room['max_players'],
            'join_token' => $room['join_token'],
            'players' => array_map(fn($p) => [
                'id' => (int)$p['player_id'],
                'name' => $p['player_name'],
                'is_host' => (bool)$p['is_host'],
                'is_online' => (bool)$p['is_online'],
                'seat' => $p['seat'] !== null ? (int)$p['seat'] : null,
            ], $pl->fetchAll(PDO::FETCH_ASSOC)),
        ],
    ]);
}

respond_json(['success' => false, 'error' => 'Unknown action'], 400);
