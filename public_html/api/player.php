<?php
// Player lifecycle: register, login, heartbeat, logout, update polling.
// Auth: X-Api-Token for register/login; + X-Player-Token for the rest.
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
api_headers();

/** @var PDO $pdo */
$action = get_param('action') ?? '';
$game = api_auth_game();
$input = json_input();

// Issue the player token set: plaintext column when no TOKEN_PEPPER
// (dev installs), else digest + encrypted owner copy so login can return
// the same credential without keeping plaintext.
function issue_player_token() {
    $token = generate_uuid();
    $digest = player_token_digest($token);
    return [$token, $digest, $digest === null ? $token : null, secret_store($token)];
}

// ====================== REGISTER ======================
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_429('player_register', 30, 300);

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        respond_json(['success' => false, 'error' => 'Player name is required (max 100 chars)'], 400);
    }

    [$token, $digest, $plain, $enc] = issue_player_token();
    try {
        $pdo->prepare("
            INSERT INTO players (game_id, name, token, token_hash, token_enc,
                                 is_online, last_login, last_heartbeat)
            VALUES (:gid, :name, :token, :hash, :enc, 1, NOW(), NOW())
        ")->execute([
            'gid' => $game['id'], 'name' => $name,
            'token' => $plain, 'hash' => $digest, 'enc' => $enc,
        ]);
        $playerId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            respond_json(['success' => false, 'error' => 'Player name already taken'], 409);
        }
        throw $e;
    }

    respond_json([
        'success' => true,
        'player' => ['id' => $playerId, 'name' => $name],
        'player_token' => $token,
    ]);
}

// ====================== LOGIN ======================
// Re-authenticates an existing player by name; returns the stored token, or
// rotates to a fresh one when the original is not retrievable.
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_429('player_login', 30, 300);

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        respond_json(['success' => false, 'error' => 'Player name is required'], 400);
    }
    $stmt = $pdo->prepare("SELECT * FROM players WHERE game_id = ? AND name = ?");
    $stmt->execute([$game['id'], $name]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$player) {
        respond_json(['success' => false, 'error' => 'Player not found'], 404);
    }

    $token = secret_read($player['token_enc'] ?? '');
    if ($token === null && is_string($player['token']) && $player['token'] !== '') {
        $token = $player['token'];
    }
    if ($token === null) {
        // Rotates the credential — existing sessions using it are dropped.
        [$token, $digest, $plain, $enc] = issue_player_token();
        $pdo->prepare("UPDATE players SET token = ?, token_hash = ?, token_enc = ? WHERE id = ?")
            ->execute([$plain, $digest, $enc, $player['id']]);
    }

    $pdo->prepare("UPDATE players SET is_online = 1, last_login = NOW(), last_heartbeat = NOW() WHERE id = ?")
        ->execute([$player['id']]);
    $pdo->prepare("UPDATE room_players SET is_online = 1, last_heartbeat = NOW() WHERE player_id = ?")
        ->execute([$player['id']]);

    respond_json([
        'success' => true,
        'player' => ['id' => (int)$player['id'], 'name' => $player['name']],
        'player_token' => $token,
    ]);
}

// ---------- Everything below requires a player token ----------
$player = api_auth_player($game);

// ====================== HEARTBEAT ======================
if ($action === 'heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_429('heartbeat', 120, 60, 'p' . $player['id']);
    $pdo->prepare("UPDATE players SET is_online = 1, last_heartbeat = NOW() WHERE id = ?")
        ->execute([$player['id']]);
    $pdo->prepare("UPDATE room_players SET is_online = 1, last_heartbeat = NOW() WHERE player_id = ?")
        ->execute([$player['id']]);
    respond_json(['success' => true, 'server_time' => isoUtc('now')]);
}

// ====================== LOGOUT ======================
if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("UPDATE players SET is_online = 0, last_logout = NOW() WHERE id = ?")
        ->execute([$player['id']]);
    $pdo->prepare("UPDATE room_players SET is_online = 0 WHERE player_id = ?")
        ->execute([$player['id']]);
    respond_json(['success' => true]);
}

// ====================== ME ======================
if ($action === 'me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $membership = player_room_membership($player['id']);
    respond_json([
        'success' => true,
        'player' => [
            'id' => (int)$player['id'],
            'name' => $player['name'],
            'is_online' => (bool)$player['is_online'],
            'room_id' => $membership['room_id'] ?? null,
            // Dual form: parsed for typed SDKs, _json string for Unity
            // (JsonUtility can't decode arbitrary nested objects).
            'data' => json_decode($player['player_data'] ?? '{}', true),
            'data_json' => $player['player_data'] ?? '{}',
        ],
    ]);
}

// ====================== UPDATES (poll) ======================
if ($action === 'updates' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    rate_limit_or_429('player_updates', 120, 60, 'p' . $player['id']);
    $limit = min(max((int)(get_param('limit') ?? 50), 1), 200);

    $stmt = $pdo->prepare("
        SELECT update_id, room_id, from_player_id, type, data, created_at
        FROM player_updates
        WHERE target_player_id = ? AND status = 'pending'
        ORDER BY created_at LIMIT $limit
    ");
    $stmt->execute([$player['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $ids = array_column($rows, 'update_id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE player_updates SET status = 'delivered', delivered_at = NOW() WHERE update_id IN ($ph)")
            ->execute($ids);
    }

    respond_json([
        'success' => true,
        'updates' => array_map(fn($r) => [
            'id' => $r['update_id'],
            'room_id' => $r['room_id'],
            'from' => $r['from_player_id'] !== null ? (int)$r['from_player_id'] : null,
            'type' => $r['type'],
            'data' => json_decode($r['data'], true),
            'data_json' => $r['data'],
            'at' => isoUtc($r['created_at']),
        ], $rows),
    ]);
}

respond_json(['success' => false, 'error' => 'Unknown action'], 400);
