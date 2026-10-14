<?php

namespace Mlc\Engine;

use PDO;

// Production StateStore — maps scopes/effects onto the standalone schema.
// All writes run inside the caller's transaction (Runtime owns it); the room
// row is already locked FOR UPDATE before execution starts.
final class PdoStateStore implements StateStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function loadRoomState(Context $ctx): array
    {
        $roomId = $ctx->roomId();
        // FOR UPDATE: serializes concurrent actions in this room and holds
        // the state row consistent for the whole execution.
        $stmt = $this->pdo->prepare("SELECT state FROM room_state WHERE room_id = ? FOR UPDATE");
        $stmt->execute([$roomId]);
        $json = $stmt->fetchColumn();
        return $json === false ? [] : (json_decode((string)$json, true) ?: []);
    }

    public function saveRoomState(Context $ctx, array $state): void
    {
        $this->pdo->prepare("
            INSERT INTO room_state (room_id, state, state_version)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE state = VALUES(state), state_version = state_version + 1
        ")->execute([$ctx->roomId(), json_encode($state, JSON_UNESCAPED_UNICODE)]);
    }

    public function loadPlayerState(Context $ctx, int $playerId): array
    {
        $stmt = $this->pdo->prepare("SELECT state FROM player_state WHERE room_id = ? AND player_id = ?");
        $stmt->execute([$ctx->roomId(), $playerId]);
        $json = $stmt->fetchColumn();
        return $json === false ? [] : (json_decode((string)$json, true) ?: []);
    }

    public function savePlayerState(Context $ctx, int $playerId, array $state): void
    {
        $this->pdo->prepare("
            INSERT INTO player_state (room_id, player_id, state) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE state = VALUES(state)
        ")->execute([$ctx->roomId(), $playerId, json_encode($state, JSON_UNESCAPED_UNICODE)]);
    }

    public function loadGameState(Context $ctx): array
    {
        $stmt = $this->pdo->prepare("SELECT state FROM game_state WHERE game_id = ? FOR UPDATE");
        $stmt->execute([(int)$ctx->game['id']]);
        $json = $stmt->fetchColumn();
        return $json === false ? [] : (json_decode((string)$json, true) ?: []);
    }

    public function saveGameState(Context $ctx, array $state): void
    {
        $this->pdo->prepare("
            INSERT INTO game_state (game_id, state, state_version) VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE state = VALUES(state), state_version = state_version + 1
        ")->execute([(int)$ctx->game['id'], json_encode($state, JSON_UNESCAPED_UNICODE)]);
    }

    public function roomPlayers(Context $ctx): array
    {
        $stmt = $this->pdo->prepare("
            SELECT player_id, player_name, is_host, is_online, seat
            FROM room_players WHERE room_id = ? ORDER BY seat ASC, joined_at ASC
        ");
        $stmt->execute([$ctx->roomId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sendUpdate(Context $ctx, ?int $targetPlayerId, bool $othersOnly, string $type, mixed $data): void
    {
        $targets = $targetPlayerId !== null ? [$targetPlayerId] : $this->broadcastTargets($ctx, $othersOnly);
        if ($targets === []) {
            return;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO player_updates (update_id, room_id, game_id, from_player_id, target_player_id, type, data)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach ($targets as $pid) {
            $stmt->execute([
                generate_uuid(),
                $ctx->roomId(),
                (int)$ctx->game['id'],
                $ctx->player['id'] ?? null,
                $pid,
                $type,
                $json,
            ]);
        }
    }

    /** @return list<int> */
    private function broadcastTargets(Context $ctx, bool $othersOnly): array
    {
        $sql = "SELECT player_id FROM room_players WHERE room_id = ?";
        $args = [$ctx->roomId()];
        if ($othersOnly && isset($ctx->player['id'])) {
            $sql .= " AND player_id != ?";
            $args[] = (int)$ctx->player['id'];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function scheduleEvent(Context $ctx, string $timer, int $runAtUnix, mixed $payload): void
    {
        $this->pdo->prepare("
            INSERT INTO scheduled_events (event_id, game_id, room_id, player_id, trigger_name, payload, run_at)
            VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))
        ")->execute([
            generate_uuid(),
            (int)$ctx->game['id'],
            $ctx->room['room_id'] ?? null,
            $ctx->player['id'] ?? null,
            $timer,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            $runAtUnix,
        ]);
    }

    public function cancelEvents(Context $ctx, string $timer): void
    {
        $this->pdo->prepare("
            UPDATE scheduled_events SET status = 'cancelled'
            WHERE game_id = ? AND room_id <=> ? AND trigger_name = ? AND status = 'pending'
        ")->execute([(int)$ctx->game['id'], $ctx->room['room_id'] ?? null, $timer]);
    }

    public function kickPlayer(Context $ctx, int $playerId): void
    {
        $this->pdo->prepare("DELETE FROM room_players WHERE room_id = ? AND player_id = ?")
            ->execute([$ctx->roomId(), $playerId]);
    }

    public function setRoomStatus(Context $ctx, string $status): void
    {
        $this->pdo->prepare("UPDATE rooms SET status = ? WHERE room_id = ?")
            ->execute([$status, $ctx->roomId()]);
        if ($ctx->room !== null) {
            $ctx->room['status'] = $status;
        }
    }

    public function log(Context $ctx, string $action, mixed $payload, string $result, ?string $reason, int $steps, int $durationMs): void
    {
        $this->pdo->prepare("
            INSERT INTO action_log (game_id, room_id, player_id, action, payload, result, reason, steps_used, duration_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            (int)$ctx->game['id'],
            $ctx->room['room_id'] ?? null,
            $ctx->player['id'] ?? null,
            mb_substr($action, 0, 100),
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            $result,
            $reason === null ? null : mb_substr($reason, 0, 255),
            $steps,
            $durationMs,
        ]);
    }
}
