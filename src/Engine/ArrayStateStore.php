<?php

namespace Mlc\Engine;

// In-memory StateStore — unit tests and the cabinet simulator. Everything
// lands on public arrays so a test can assert the whole post-run world.
final class ArrayStateStore implements StateStore
{
    /** @var array<string,array> roomId => state */
    public array $roomStates = [];
    /** @var array<int,array> "roomId:playerId" => state */
    public array $playerStates = [];
    /** @var array<int,array> gameId => state */
    public array $gameStates = [];
    /** @var list<array> room member rows */
    public array $members = [];
    /** @var list<array> delivered updates {player_id,type,data} */
    public array $sentUpdates = [];
    /** @var list<array> scheduled events */
    public array $events = [];
    /** @var list<string> */
    public array $cancelled = [];
    /** @var list<array> action log rows */
    public array $log = [];

    private function key(Context $ctx, int $playerId): string
    {
        return ($ctx->room['room_id'] ?? '-') . ':' . $playerId;
    }

    public function loadRoomState(Context $ctx): array
    {
        return $this->roomStates[$ctx->roomId()] ?? [];
    }

    public function loadPlayerState(Context $ctx, int $playerId): array
    {
        return $this->playerStates[$this->key($ctx, $playerId)] ?? [];
    }

    public function loadGameState(Context $ctx): array
    {
        return $this->gameStates[(int)$ctx->game['id']] ?? [];
    }

    public function saveRoomState(Context $ctx, array $state): void
    {
        $this->roomStates[$ctx->roomId()] = $state;
    }

    public function savePlayerState(Context $ctx, int $playerId, array $state): void
    {
        $this->playerStates[$this->key($ctx, $playerId)] = $state;
    }

    public function saveGameState(Context $ctx, array $state): void
    {
        $this->gameStates[(int)$ctx->game['id']] = $state;
    }

    public function roomPlayers(Context $ctx): array
    {
        return array_values(array_filter(
            $this->members,
            fn($m) => ($m['room_id'] ?? null) === ($ctx->room['room_id'] ?? null)
        ));
    }

    public function sendUpdate(Context $ctx, ?int $targetPlayerId, bool $othersOnly, string $type, mixed $data): void
    {
        $targets = $targetPlayerId !== null
            ? [$targetPlayerId]
            : array_map(
                fn($m) => (int)$m['player_id'],
                array_filter(
                    $this->roomPlayers($ctx),
                    fn($m) => !$othersOnly || (int)$m['player_id'] !== ($ctx->player['id'] ?? -1)
                )
            );
        foreach ($targets as $pid) {
            $this->sentUpdates[] = ['player_id' => $pid, 'type' => $type, 'data' => $data];
        }
    }

    public function scheduleEvent(Context $ctx, string $timer, int $runAtUnix, mixed $payload): void
    {
        $this->events[] = [
            'timer' => $timer,
            'run_at' => $runAtUnix,
            'payload' => $payload,
            'room_id' => $ctx->room['room_id'] ?? null,
            'player_id' => $ctx->player['id'] ?? null,
        ];
    }

    public function cancelEvents(Context $ctx, string $timer): void
    {
        $this->cancelled[] = $timer;
    }

    public function log(Context $ctx, string $action, mixed $payload, string $result, ?string $reason, int $steps, int $durationMs): void
    {
        $this->log[] = [
            'action' => $action, 'result' => $result, 'reason' => $reason,
            'steps' => $steps, 'duration_ms' => $durationMs,
        ];
    }
}
