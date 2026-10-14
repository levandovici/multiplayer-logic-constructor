<?php

namespace Mlc\Engine;

// Persistence seam for the engine. PdoStateStore backs production
// (transactional MySQL); ArrayStateStore backs unit tests and the cabinet
// simulator. The executor only ever talks to this interface.
interface StateStore
{
    /** Room-scope state map (empty when the room has none yet). */
    public function loadRoomState(Context $ctx): array;

    /** Player-scope state for one member of the room. */
    public function loadPlayerState(Context $ctx, int $playerId): array;

    /** Game-scope state map. */
    public function loadGameState(Context $ctx): array;

    public function saveRoomState(Context $ctx, array $state): void;

    public function savePlayerState(Context $ctx, int $playerId, array $state): void;

    public function saveGameState(Context $ctx, array $state): void;

    /**
     * Member rows for ctx->room: each has
     * {player_id, player_name, is_host, is_online, seat}.
     * @return list<array>
     */
    public function roomPlayers(Context $ctx): array;

    /**
     * Queue a player_updates delivery.
     * $targetPlayerId set   → that player only
     * null + $othersOnly    → all members except the acting player
     * null + !$othersOnly   → all members
     */
    public function sendUpdate(Context $ctx, ?int $targetPlayerId, bool $othersOnly, string $type, mixed $data): void;

    /** Enqueue a timer that will fire `on_timer` triggers named $timer. */
    public function scheduleEvent(Context $ctx, string $timer, int $runAtUnix, mixed $payload): void;

    /** Cancel pending (not yet running) events named $timer for this room. */
    public function cancelEvents(Context $ctx, string $timer): void;

    /** Remove a player from the room (player.kick block). */
    public function kickPlayer(Context $ctx, int $playerId): void;

    /** Set rooms.status (match.start/match.end blocks). */
    public function setRoomStatus(Context $ctx, string $status): void;

    /** Append one action_log row summarising the run. */
    public function log(Context $ctx, string $action, mixed $payload, string $result, ?string $reason, int $steps, int $durationMs): void;
}
