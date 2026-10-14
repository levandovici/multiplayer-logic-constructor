<?php

namespace Mlc\Engine;

use PDO;
use Throwable;

// Runtime — the seam between HTTP/cron and the interpreter. One fire() call =
// one transaction: the room row is locked FOR UPDATE, matching triggers run,
// dirty scopes + outbox flush, action_log row written, commit. A reject or
// engine error rolls back everything — state is never half-applied.
final class Runtime
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Published program row for a game, or null. */
    public function publishedProgram(array $game): ?array
    {
        if (empty($game['published_program_id'])) {
            return null;
        }
        $stmt = $this->pdo->prepare("
            SELECT v.id, v.program_id, v.version, v.ast, v.checksum
            FROM program_versions v WHERE v.id = ?
        ");
        $stmt->execute([(int)$game['published_program_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Execute triggers for an event.
     *
     * @param array   $game    games row (must carry published_program_id)
     * @param ?string $roomId  room context — locked FOR UPDATE when set
     * @param ?array  $player  players row — the acting player (null for timers)
     * @param string  $event   on_player_action / on_timer / on_player_join / …
     * @param array   $payload client args or event data
     * @param ?string $key     action name / timer name / message name
     * @return array{ran:bool,result:string,response:mixed,reason:?string,steps:int,duration_ms:int,version:?int}
     */
    public function fire(array $game, ?string $roomId, ?array $player, string $event, array $payload = [], ?string $key = null): array
    {
        $start = microtime(true);
        $ownTxn = !$this->pdo->inTransaction();
        if ($ownTxn) {
            $this->pdo->beginTransaction();
        }

        $store = new PdoStateStore($this->pdo);
        $ctx = null;
        $versionRow = null;

        try {
            $room = null;
            if ($roomId !== null) {
                $stmt = $this->pdo->prepare("SELECT * FROM rooms WHERE room_id = ? AND game_id = ? FOR UPDATE");
                $stmt->execute([$roomId, (int)$game['id']]);
                $room = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($room === null) {
                    throw new EngineError('room not found');
                }
            }

            $versionRow = $this->publishedProgram($game);
            if ($versionRow === null) {
                if ($ownTxn) {
                    $this->pdo->commit();
                }
                return $this->result('noop', null, null, 0, $start);
            }

            $program = Program::fromJson((string)$versionRow['ast']);
            $ctx = new Context($store, $game, $room, $player, $payload, $event, $key, $program->defaultsAll());
            $exec = new Executor($program, $ctx);

            try {
                $exec->fire();
            } catch (Reject $r) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $store->log($ctx, (string)$key, $payload, 'rejected', $r->reason, $exec->stepsUsed(), $this->ms($start));
                return $this->result('rejected', null, $r->reason, $exec->stepsUsed(), $start, (int)$versionRow['version']);
            } catch (BudgetExhausted|EngineError $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $store->log($ctx, (string)$key, $payload, 'error', $e->getMessage(), $exec->stepsUsed(), $this->ms($start));
                return $this->result('error', null, $e->getMessage(), $exec->stepsUsed(), $start, (int)$versionRow['version']);
            }

            $ctx->flush();
            $store->log($ctx, (string)$key, $payload, 'ok', null, $exec->stepsUsed(), $this->ms($start));
            if ($ownTxn) {
                $this->pdo->commit();
            }

            return [
                'ran' => true,
                'result' => 'ok',
                'response' => $ctx->response,
                'reason' => null,
                'steps' => $exec->stepsUsed(),
                'duration_ms' => $this->ms($start),
                'version' => (int)$versionRow['version'],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('engine runtime error: ' . redact_log_value($e->getMessage()));
            if ($ctx !== null) {
                try {
                    $store->log($ctx, (string)$key, $payload, 'error', 'internal', 0, $this->ms($start));
                } catch (Throwable) {
                    // logging must never mask the original failure
                }
            }
            return $this->result('error', null, 'internal error', 0, $start, $versionRow ? (int)$versionRow['version'] : null);
        }
    }

    private function result(string $result, mixed $response, ?string $reason, int $steps, float $start, ?int $version = null): array
    {
        return [
            'ran' => $result !== 'noop',
            'result' => $result,
            'response' => $response,
            'reason' => $reason,
            'steps' => $steps,
            'duration_ms' => $this->ms($start),
            'version' => $version,
        ];
    }

    private function ms(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }
}
