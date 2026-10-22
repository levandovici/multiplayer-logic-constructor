<?php

namespace Mlc\Engine;

// Execution context for one trigger run: who acted, in which room/game, with
// what payload — plus lazily-loaded persistent scopes and the outbox of
// effects (updates, scheduled events) flushed once at commit time.
final class Context
{
    /** Serialized ceiling per persistent scope (1 MiB) and per local Scope —
     *  caps total state a program can hold, not just single values. */
    public const MAX_SCOPE_BYTES = 1048576;

    /** Outbox row budgets per trigger run — beyond these the run hard-fails
     *  instead of flushing thousands of update/timer/log rows. */
    public const MAX_UPDATES = 256;
    public const MAX_SCHEDULED = 64;
    public const MAX_CANCELLED = 64;
    public const MAX_LOG_LINES = 128;

    /** @var array<string,mixed> games row */
    public array $game;
    /** @var array<string,mixed>|null rooms row — null outside a room */
    public ?array $room;
    /** @var array<string,mixed>|null players row — the acting player */
    public ?array $player;
    /** @var array<string,mixed> request payload (action args / event data) */
    public array $payload;
    public string $event;
    /** Trigger key — action name for on_player_action, timer for on_timer. */
    public ?string $key;

    /** Response body returned to the client (set by `respond`). */
    public mixed $response = null;
    public bool $responded = false;

    /** Buffered effects — flushed by Runtime after successful execution. */
    /** @var list<array{target:?int,others:bool,type:string,data:mixed}> */
    public array $updates = [];
    /** @var list<array{timer:string,run_at:int,payload:mixed}> */
    public array $scheduled = [];
    /** @var list<string> timer names cancelled */
    public array $cancelledTimers = [];
    /** @var list<string> free-form log lines (action_log.detail) */
    public array $logLines = [];

    /** @var array<int,array>|null cached member rows for the room */
    private ?array $roomPlayersCache = null;

    // Lazily-loaded persistent scopes + dirty tracking (write-back on flush).
    private ?array $roomState = null;
    private ?array $gameState = null;
    /** @var array<int,array> playerId => state map */
    private array $playerStates = [];
    /** @var array<int,bool> playerId => dirty */
    private array $playerDirty = [];
    private bool $roomDirty = false;
    private bool $gameDirty = false;
    /** Approximate serialized bytes per loaded scope (MAX_SCOPE_BYTES guard). */
    private int $roomBytes = 0;
    private int $gameBytes = 0;
    /** @var array<int,int> playerId => approx bytes */
    private array $playerBytes = [];

    /** @var array{player:array,room:array,game:array} declared variable
     *  defaults — merged under stored state so a declared var always reads. */
    private array $defaults = ['player' => [], 'room' => [], 'game' => []];

    public function __construct(
        public readonly StateStore $store,
        array $game,
        ?array $room,
        ?array $player,
        array $payload = [],
        string $event = '',
        ?string $key = null,
        array $defaults = [],
    ) {
        $this->game = $game;
        $this->room = $room;
        $this->player = $player;
        $this->payload = $payload;
        $this->event = $event;
        $this->key = $key;
        $this->defaults = array_replace_recursive($this->defaults, $defaults);
    }

    /** The acting player's room_players row fields, resolved via members. */
    public function playerIsHost(): bool
    {
        if ($this->player === null) {
            return false;
        }
        foreach ($this->roomPlayers() as $m) {
            if ((int)$m['player_id'] === (int)$this->player['id']) {
                return !empty($m['is_host']);
            }
        }
        return false;
    }

    public function playerSeat(): ?int
    {
        if ($this->player === null) {
            return null;
        }
        foreach ($this->roomPlayers() as $m) {
            if ((int)$m['player_id'] === (int)$this->player['id']) {
                return $m['seat'] !== null ? (int)$m['seat'] : null;
            }
        }
        return null;
    }

    public function inRoom(): bool
    {
        return $this->room !== null;
    }

    public function roomId(): string
    {
        if ($this->room === null) {
            throw new EngineError('this block requires a room context');
        }
        return (string)$this->room['room_id'];
    }

    public function playerId(): int
    {
        if ($this->player === null) {
            throw new EngineError('this block requires an acting player');
        }
        return (int)$this->player['id'];
    }

    /** Resolve a `player` expr value: null/'self' → acting player, else id. */
    public function resolvePlayerId(mixed $ref): int
    {
        if ($ref === null || $ref === '' || $ref === 'self') {
            return $this->playerId();
        }
        return Value::int($ref);
    }

    /** Member rows of the current room (cached per execution). */
    public function roomPlayers(): array
    {
        if ($this->roomPlayersCache === null) {
            $this->roomPlayersCache = $this->room === null ? [] : $this->store->roomPlayers($this);
        }
        return $this->roomPlayersCache;
    }

    // ---------- room scope ----------

    public function roomGet(string $name, mixed $default = null): mixed
    {
        $this->loadRoom();
        return $this->roomState[$name] ?? $default;
    }

    public function roomSet(string $name, mixed $value): void
    {
        Value::assertSize($value);
        $this->loadRoom();
        $this->roomBytes += Value::byteSize($value) - Value::byteSize($this->roomState[$name] ?? null);
        if ($this->roomBytes > self::MAX_SCOPE_BYTES) {
            throw new BudgetExhausted('room state exceeds ' . self::MAX_SCOPE_BYTES . ' bytes');
        }
        $this->roomState[$name] = $value;
        $this->roomDirty = true;
    }

    private function loadRoom(): void
    {
        if ($this->roomState === null) {
            $this->roomState = array_replace_recursive(
                $this->defaults['room'],
                $this->store->loadRoomState($this)
            );
            $this->roomBytes = Value::byteSize($this->roomState);
        }
    }

    // ---------- game scope ----------

    public function gameGet(string $name, mixed $default = null): mixed
    {
        $this->loadGame();
        return $this->gameState[$name] ?? $default;
    }

    public function gameSet(string $name, mixed $value): void
    {
        Value::assertSize($value);
        $this->loadGame();
        $this->gameBytes += Value::byteSize($value) - Value::byteSize($this->gameState[$name] ?? null);
        if ($this->gameBytes > self::MAX_SCOPE_BYTES) {
            throw new BudgetExhausted('game state exceeds ' . self::MAX_SCOPE_BYTES . ' bytes');
        }
        $this->gameState[$name] = $value;
        $this->gameDirty = true;
    }

    private function loadGame(): void
    {
        if ($this->gameState === null) {
            $this->gameState = array_replace_recursive(
                $this->defaults['game'],
                $this->store->loadGameState($this)
            );
            $this->gameBytes = Value::byteSize($this->gameState);
        }
    }

    // ---------- player scope ----------

    public function playerGet(?int $playerId, string $name, mixed $default = null): mixed
    {
        $playerId ??= $this->playerId();
        return ($this->playerState($playerId))[$name] ?? $default;
    }

    public function playerSet(?int $playerId, string $name, mixed $value): void
    {
        Value::assertSize($value);
        $playerId ??= $this->playerId();
        $state = $this->playerState($playerId); // ensure loaded
        $this->playerBytes[$playerId] += Value::byteSize($value) - Value::byteSize($state[$name] ?? null);
        if ($this->playerBytes[$playerId] > self::MAX_SCOPE_BYTES) {
            throw new BudgetExhausted('player state exceeds ' . self::MAX_SCOPE_BYTES . ' bytes');
        }
        $this->playerStates[$playerId][$name] = $value;
        $this->playerDirty[$playerId] = true;
    }

    private function playerState(int $playerId): array
    {
        if (!array_key_exists($playerId, $this->playerStates)) {
            $this->playerStates[$playerId] = array_replace_recursive(
                $this->defaults['player'],
                $this->store->loadPlayerState($this, $playerId)
            );
            $this->playerBytes[$playerId] = Value::byteSize($this->playerStates[$playerId]);
        }
        return $this->playerStates[$playerId];
    }

    // ---------- outbox ----------

    public function respond(mixed $value): void
    {
        Value::assertSize($value);
        $this->response = $value;
        $this->responded = true;
    }

    private function pushUpdate(?int $playerId, bool $others, string $type, mixed $data): void
    {
        if (count($this->updates) >= self::MAX_UPDATES) {
            throw new BudgetExhausted('update budget ' . self::MAX_UPDATES . ' exceeded');
        }
        Value::assertSize($data);
        $this->updates[] = ['target' => $playerId, 'others' => $others, 'type' => $type, 'data' => $data];
    }

    public function sendToPlayer(int $playerId, string $type, mixed $data): void
    {
        $this->pushUpdate($playerId, false, $type, $data);
    }

    public function sendToRoom(string $type, mixed $data): void
    {
        $this->pushUpdate(null, false, $type, $data);
    }

    public function sendToOthers(string $type, mixed $data): void
    {
        $this->pushUpdate(null, true, $type, $data);
    }

    public function schedule(string $timer, int $delaySeconds, mixed $payload): void
    {
        if (count($this->scheduled) >= self::MAX_SCHEDULED) {
            throw new BudgetExhausted('schedule budget ' . self::MAX_SCHEDULED . ' exceeded');
        }
        Value::assertSize($payload);
        $this->scheduled[] = ['timer' => $timer, 'run_at' => time() + $delaySeconds, 'payload' => $payload];
    }

    public function cancelTimer(string $timer): void
    {
        if (count($this->cancelledTimers) >= self::MAX_CANCELLED) {
            throw new BudgetExhausted('cancel budget ' . self::MAX_CANCELLED . ' exceeded');
        }
        $this->cancelledTimers[] = $timer;
    }

    /** Free-form audit line (action_log.detail / simulate log). */
    public function log(string $line): void
    {
        if (count($this->logLines) >= self::MAX_LOG_LINES) {
            throw new BudgetExhausted('log budget ' . self::MAX_LOG_LINES . ' exceeded');
        }
        Value::assertSize($line);
        $this->logLines[] = $line;
    }

    /** Persist all dirty scopes + outbox effects. Called once by Runtime. */
    public function flush(): void
    {
        if ($this->roomDirty) {
            $this->store->saveRoomState($this, $this->roomState ?? []);
        }
        if ($this->gameDirty) {
            $this->store->saveGameState($this, $this->gameState ?? []);
        }
        foreach ($this->playerDirty as $pid => $_) {
            $this->store->savePlayerState($this, $pid, $this->playerStates[$pid]);
        }
        foreach ($this->updates as $u) {
            $this->store->sendUpdate($this, $u['target'], $u['others'], $u['type'], $u['data']);
        }
        foreach ($this->scheduled as $s) {
            $this->store->scheduleEvent($this, $s['timer'], $s['run_at'], $s['payload']);
        }
        foreach ($this->cancelledTimers as $t) {
            $this->store->cancelEvents($this, $t);
        }
    }
}
