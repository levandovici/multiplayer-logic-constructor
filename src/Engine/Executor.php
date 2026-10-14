<?php

namespace Mlc\Engine;

// The block-program interpreter. fire() runs every trigger matching the
// context's event/key; each trigger gets a fresh local scope. Persistent
// scopes live in Context (write-back on flush). Control flow unwinds via
// Signals — Reject (fail the action), RespondSignal (reply + halt),
// ReturnSignal (function return), BreakSignal (loop exit).
final class Executor
{
    public int $steps = 0;
    private int $loopIters = 0;

    public function __construct(
        private Program $program,
        private Context $ctx,
        private int $stepBudget = 10000,
        private int $loopBudget = 10000,
    ) {
    }

    /** Run all triggers matching ctx->event (+key). RespondSignal halts the chain. */
    public function fire(): void
    {
        try {
            foreach ($this->program->triggersFor($this->ctx->event, $this->ctx->key) as $trigger) {
                $this->execList($trigger['body'], new \Mlc\Engine\Scope());
            }
        } catch (RespondSignal) {
            // response is set on the context — nothing more to run
        }
    }

    public function stepsUsed(): int
    {
        return $this->steps;
    }

    private function tick(): void
    {
        if (++$this->steps > $this->stepBudget) {
            throw new BudgetExhausted("step budget {$this->stepBudget} exceeded");
        }
    }

    private function loopTick(): void
    {
        if (++$this->loopIters > $this->loopBudget) {
            throw new BudgetExhausted("loop budget {$this->loopBudget} exceeded");
        }
    }

    // ====================== statements ======================

    /** @param list<array> $stmts */
    private function execList(array $stmts, Scope $scope): void
    {
        foreach ($stmts as $stmt) {
            $this->execStmt($stmt, $scope);
        }
    }

    private function execStmt(array $n, Scope $scope): void
    {
        $this->tick();
        $op = $n['op'] ?? throw new EngineError('statement node without op');

        switch ($op) {
            // ---------- variable writes ----------
            case 'set.local': case 'set.player': case 'set.room': case 'set.game':
                $this->setVar(
                    $this->opScope($op),
                    $this->varName($n),
                    $this->evalExpr($n['value'] ?? null, $scope),
                    $this->evalPlayerRef($n, $scope),
                    $scope
                );
                return;

            case 'change.local': case 'change.player': case 'change.room': case 'change.game':
                $s = $this->opScope($op);
                $name = $this->varName($n);
                $pid = $this->evalPlayerRef($n, $scope);
                $cur = $this->getVar($s, $name, $pid, $scope);
                $this->setVar($s, $name, Value::num($cur) + Value::num($this->evalExpr($n['delta'] ?? 0, $scope)), $pid, $scope);
                return;

            // ---------- named list mutation ----------
            case 'list.add':
                [$list, $w] = $this->namedVar($n, $scope);
                $list = $list === null ? [] : Value::listOf($list);
                $list[] = $this->evalExpr($n['value'] ?? null, $scope);
                $w($list);
                return;

            case 'list.insert_at':
                [$list, $w] = $this->namedVar($n, $scope);
                $list = Value::listOf($list);
                array_splice($list, Value::int($this->evalExpr($n['index'] ?? 0, $scope)), 0, [$this->evalExpr($n['value'] ?? null, $scope)]);
                $w($list);
                return;

            case 'list.set_at':
                [$list, $w] = $this->namedVar($n, $scope);
                $list = Value::listOf($list);
                $i = Value::int($this->evalExpr($n['index'] ?? 0, $scope));
                if ($i < 0 || $i >= count($list)) {
                    throw new EngineError("list index $i out of range");
                }
                $list[$i] = $this->evalExpr($n['value'] ?? null, $scope);
                $w($list);
                return;

            case 'list.remove_at':
                [$list, $w] = $this->namedVar($n, $scope);
                $list = Value::listOf($list);
                array_splice($list, Value::int($this->evalExpr($n['index'] ?? 0, $scope)), 1);
                $w($list);
                return;

            case 'list.clear':
                [, $w] = $this->namedVar($n, $scope);
                $w([]);
                return;

            // ---------- named map mutation ----------
            case 'map.set':
                [$map, $w] = $this->namedVar($n, $scope);
                $map = $map === null ? [] : Value::mapOf($map);
                $map[Value::str($this->evalExpr($n['key'] ?? '', $scope))] = $this->evalExpr($n['value'] ?? null, $scope);
                $w($map);
                return;

            case 'map.delete':
                [$map, $w] = $this->namedVar($n, $scope);
                $map = Value::mapOf($map);
                unset($map[Value::str($this->evalExpr($n['key'] ?? '', $scope))]);
                $w($map);
                return;

            // ---------- control flow ----------
            case 'if':
                $branch = Value::bool($this->evalExpr($n['cond'] ?? false, $scope))
                    ? ($n['then'] ?? [])
                    : ($n['else'] ?? []);
                $this->execList($branch, $scope);
                return;

            case 'repeat':
                $times = Value::int($this->evalExpr($n['times'] ?? 0, $scope));
                for ($i = 0; $i < $times; $i++) {
                    $this->loopTick();
                    try {
                        $this->execList($n['body'] ?? [], $scope);
                    } catch (BreakSignal) {
                        break;
                    }
                }
                return;

            case 'while':
                while (Value::bool($this->evalExpr($n['cond'] ?? false, $scope))) {
                    $this->loopTick();
                    try {
                        $this->execList($n['body'] ?? [], $scope);
                    } catch (BreakSignal) {
                        break;
                    }
                }
                return;

            case 'repeat_until':
                do {
                    $this->loopTick();
                    try {
                        $this->execList($n['body'] ?? [], $scope);
                    } catch (BreakSignal) {
                        return;
                    }
                } while (!Value::bool($this->evalExpr($n['cond'] ?? false, $scope)));
                return;

            case 'foreach':
                $list = Value::listOf($this->evalExpr($n['list'] ?? [], $scope));
                $as = $this->varName($n, 'as');
                $indexAs = is_string($n['index'] ?? null) ? $n['index'] : null;
                foreach ($list as $i => $item) {
                    $this->loopTick();
                    $scope->vars[$as] = $item;
                    if ($indexAs !== null) {
                        $scope->vars[$indexAs] = $i;
                    }
                    try {
                        $this->execList($n['body'] ?? [], $scope);
                    } catch (BreakSignal) {
                        break;
                    }
                }
                return;

            case 'foreach_player':
                $as = $this->varName($n, 'as');
                foreach ($this->ctx->roomPlayers() as $member) {
                    $this->loopTick();
                    $scope->vars[$as] = (int)$member['player_id'];
                    try {
                        $this->execList($n['body'] ?? [], $scope);
                    } catch (BreakSignal) {
                        break;
                    }
                }
                return;

            case 'break':
                throw new BreakSignal();

            // ---------- functions ----------
            case 'call':
                $this->callFunction($n, $scope); // return value discarded
                return;

            case 'return':
                throw new ReturnSignal($this->evalExpr($n['value'] ?? null, $scope));

            // ---------- action outcome ----------
            case 'respond':
                $this->ctx->respond($this->evalExpr($n['value'] ?? null, $scope));
                throw new RespondSignal();

            case 'reject':
                throw new Reject(Value::str($this->evalExpr($n['reason'] ?? 'rejected', $scope)));

            // ---------- communication ----------
            case 'send.player':
                $this->ctx->sendToPlayer(
                    $this->ctx->resolvePlayerId($this->evalExpr($n['player'] ?? null, $scope)),
                    Value::str($this->evalExpr($n['type'] ?? 'update', $scope)),
                    $this->evalExpr($n['data'] ?? null, $scope)
                );
                return;

            case 'send.room':
                $this->ctx->sendToRoom(
                    Value::str($this->evalExpr($n['type'] ?? 'update', $scope)),
                    $this->evalExpr($n['data'] ?? null, $scope)
                );
                return;

            case 'send.others':
                $this->ctx->sendToOthers(
                    Value::str($this->evalExpr($n['type'] ?? 'update', $scope)),
                    $this->evalExpr($n['data'] ?? null, $scope)
                );
                return;

            // ---------- timers ----------
            case 'schedule':
                $this->ctx->schedule(
                    Value::str($this->evalExpr($n['timer'] ?? '', $scope)),
                    max(0, Value::int($this->evalExpr($n['delay'] ?? 60, $scope))),
                    $this->evalExpr($n['payload'] ?? null, $scope)
                );
                return;

            case 'cancel_timer':
                $this->ctx->cancelTimer(Value::str($this->evalExpr($n['timer'] ?? '', $scope)));
                return;

            // ---------- audit ----------
            case 'log':
                $this->ctx->logLines[] = Value::str($this->evalExpr($n['message'] ?? '', $scope));
                return;

            default:
                if (Ops::isExpression($op)) {
                    // expression in statement position — evaluate for effects
                    // (call, random_*, …) and discard the result.
                    $this->evalExpr($n, $scope);
                    return;
                }
                throw new EngineError("unknown statement op \"$op\"");
        }
    }

    // ====================== expressions ======================

    private function evalExpr(mixed $node, Scope $scope): mixed
    {
        if (!is_array($node) || !isset($node['op'])) {
            return $node; // scalar shorthand = literal
        }
        $this->tick();
        $op = $node['op'];
        $e = fn(mixed $x): mixed => $this->evalExpr($x, $scope);

        switch ($op) {
            case 'lit':
                return $node['value'] ?? null;

            case 'var.local': case 'var.player': case 'var.room': case 'var.game':
                return $this->getVar(
                    $this->opScope($op),
                    $this->varName($node),
                    $this->evalPlayerRef($node, $scope),
                    $scope
                );

            case 'payload':
                $key = Value::str($node['key'] ?? '');
                return $this->ctx->payload[$key] ?? $e($node['default'] ?? null);

            // ---------- context ----------
            case 'ctx.player_id':
                return $this->ctx->player['id'] ?? null;
            case 'ctx.player_name':
                return $this->ctx->player['name'] ?? null;
            case 'ctx.player_is_host':
                return (bool)$this->ctx->playerIsHost();
            case 'ctx.player_seat':
                return $this->ctx->playerSeat();
            case 'ctx.room_id':
                return $this->ctx->room['room_id'] ?? null;
            case 'ctx.room_name':
                return $this->ctx->room['name'] ?? null;
            case 'ctx.room_status':
                return $this->ctx->room['status'] ?? null;
            case 'ctx.now':
                return time();
            case 'ctx.action':
            case 'ctx.timer':
                return $this->ctx->key;
            case 'ctx.event':
                return $this->ctx->event;

            case 'room.players':
                return array_map(fn($m) => [
                    'id' => (int)$m['player_id'],
                    'name' => $m['player_name'],
                    'is_host' => (bool)$m['is_host'],
                    'is_online' => (bool)$m['is_online'],
                    'seat' => $m['seat'] !== null ? (int)$m['seat'] : null,
                ], $this->ctx->roomPlayers());

            case 'room.player_count':
                return count($this->ctx->roomPlayers());

            // ---------- math ----------
            case 'add': return Value::num($e($node['a'] ?? 0)) + Value::num($e($node['b'] ?? 0));
            case 'sub': return Value::num($e($node['a'] ?? 0)) - Value::num($e($node['b'] ?? 0));
            case 'mul': return Value::num($e($node['a'] ?? 0)) * Value::num($e($node['b'] ?? 0));
            case 'div':
                $b = Value::num($e($node['b'] ?? 1));
                if ($b == 0) {
                    throw new EngineError('division by zero');
                }
                return Value::num($e($node['a'] ?? 0)) / $b;
            case 'mod':
                $b = Value::num($e($node['b'] ?? 1));
                if ($b == 0) {
                    throw new EngineError('modulo by zero');
                }
                return Value::num($e($node['a'] ?? 0)) % $b;
            case 'abs': return abs(Value::num($e($node['v'] ?? 0)));
            case 'min': return min(Value::num($e($node['a'] ?? 0)), Value::num($e($node['b'] ?? 0)));
            case 'max': return max(Value::num($e($node['a'] ?? 0)), Value::num($e($node['b'] ?? 0)));
            case 'clamp':
                return max(
                    Value::num($e($node['min'] ?? 0)),
                    min(Value::num($e($node['max'] ?? 0)), Value::num($e($node['v'] ?? 0)))
                );
            case 'floor': return (int)floor(Value::num($e($node['v'] ?? 0)));
            case 'ceil': return (int)ceil(Value::num($e($node['v'] ?? 0)));
            case 'round': return (int)round(Value::num($e($node['v'] ?? 0)));
            case 'pow': return Value::num($e($node['a'] ?? 0)) ** Value::num($e($node['b'] ?? 0));
            case 'sqrt': return sqrt(Value::num($e($node['v'] ?? 0)));
            case 'random_int': return random_int(
                Value::int($e($node['min'] ?? 0)),
                Value::int($e($node['max'] ?? 0))
            );
            case 'random_float': return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
            case 'random_pick':
                $list = Value::listOf($e($node['list'] ?? []));
                return $list === [] ? null : $list[random_int(0, count($list) - 1)];

            // ---------- comparison & logic ----------
            case 'eq': return Value::equals($e($node['a'] ?? null), $e($node['b'] ?? null));
            case 'ne': return !Value::equals($e($node['a'] ?? null), $e($node['b'] ?? null));
            case 'lt': return $e($node['a'] ?? null) < $e($node['b'] ?? null);
            case 'le': return $e($node['a'] ?? null) <= $e($node['b'] ?? null);
            case 'gt': return $e($node['a'] ?? null) > $e($node['b'] ?? null);
            case 'ge': return $e($node['a'] ?? null) >= $e($node['b'] ?? null);
            case 'and':
                return Value::bool($e($node['a'] ?? false)) && Value::bool($e($node['b'] ?? false));
            case 'or':
                return Value::bool($e($node['a'] ?? false)) || Value::bool($e($node['b'] ?? false));
            case 'not': return !Value::bool($e($node['v'] ?? false));
            case 'xor': return Value::bool($e($node['a'] ?? false)) xor Value::bool($e($node['b'] ?? false));
            case 'choose':
                return Value::bool($e($node['cond'] ?? false))
                    ? $e($node['then'] ?? null)
                    : $e($node['else'] ?? null);

            // ---------- text ----------
            case 'concat':
                $parts = $node['args'] ?? [$node['a'] ?? '', $node['b'] ?? ''];
                return implode('', array_map(fn($p) => Value::str($e($p)), $parts));
            case 'text_length': return mb_strlen(Value::str($e($node['v'] ?? '')));
            case 'text_contains':
                return str_contains(
                    Value::str($e($node['haystack'] ?? '')),
                    Value::str($e($node['needle'] ?? ''))
                );
            case 'upper': return mb_strtoupper(Value::str($e($node['v'] ?? '')));
            case 'lower': return mb_strtolower(Value::str($e($node['v'] ?? '')));
            case 'substring':
                $s = Value::str($e($node['v'] ?? ''));
                $start = Value::int($e($node['start'] ?? 0));
                $len = isset($node['length']) ? Value::int($e($node['length'])) : null;
                return mb_substr($s, $start, $len);
            case 'format':
                $tpl = Value::str($e($node['template'] ?? ''));
                $args = Value::mapOf($e($node['args'] ?? []));
                return preg_replace_callback(
                    '/\{(\w+)\}/',
                    fn($m) => Value::str($args[$m[1]] ?? ''),
                    $tpl
                );

            // ---------- list expressions ----------
            case 'list_lit':
                return array_map($e, $node['items'] ?? []);
            case 'list_get':
                $list = Value::listOf($e($node['list'] ?? []));
                return $list[Value::int($e($node['index'] ?? 0))] ?? $e($node['default'] ?? null);
            case 'list_length': return count(Value::listOf($e($node['list'] ?? [])));
            case 'list_contains':
                $needle = $e($node['value'] ?? null);
                foreach (Value::listOf($e($node['list'] ?? [])) as $item) {
                    if (Value::equals($item, $needle)) {
                        return true;
                    }
                }
                return false;
            case 'list_index_of':
                $needle = $e($node['value'] ?? null);
                foreach (Value::listOf($e($node['list'] ?? [])) as $i => $item) {
                    if (Value::equals($item, $needle)) {
                        return $i;
                    }
                }
                return -1;
            case 'list_shuffle':
                $list = Value::listOf($e($node['list'] ?? []));
                // Fisher–Yates on CSPRNG — server-side shuffle clients can't rig.
                for ($i = count($list) - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
                }
                return $list;
            case 'list_slice':
                return array_slice(
                    Value::listOf($e($node['list'] ?? [])),
                    Value::int($e($node['start'] ?? 0)),
                    isset($node['length']) ? Value::int($e($node['length'])) : null
                );
            case 'list_join':
                return implode(
                    Value::str($e($node['sep'] ?? ',')),
                    array_map(fn($x) => Value::str($x), Value::listOf($e($node['list'] ?? [])))
                );

            // ---------- map expressions ----------
            case 'map_lit':
                $out = [];
                foreach (($node['entries'] ?? []) as $entry) {
                    $out[Value::str($e($entry['key'] ?? ''))] = $e($entry['value'] ?? null);
                }
                return $out;
            case 'map_get':
                $map = Value::mapOf($e($node['map'] ?? []));
                $key = Value::str($e($node['key'] ?? ''));
                return $map[$key] ?? $e($node['default'] ?? null);
            case 'map_has':
                return array_key_exists(
                    Value::str($e($node['key'] ?? '')),
                    Value::mapOf($e($node['map'] ?? []))
                );
            case 'map_keys': return array_keys(Value::mapOf($e($node['map'] ?? [])));
            case 'map_values': return array_values(Value::mapOf($e($node['map'] ?? [])));

            // ---------- conversion & inspection ----------
            case 'to_number': return Value::num($e($node['v'] ?? 0));
            case 'to_string': return Value::str($e($node['v'] ?? ''));
            case 'to_bool': return Value::bool($e($node['v'] ?? false));
            case 'is_null': return $e($node['v'] ?? null) === null;
            case 'is_list': return is_array($v = $e($node['v'] ?? null)) && Value::isList($v);
            case 'is_map': return is_array($v = $e($node['v'] ?? null)) && !Value::isList($v);
            case 'is_number': return is_int($v = $e($node['v'] ?? null)) || is_float($v);
            case 'is_string': return is_string($e($node['v'] ?? null));
            case 'is_bool': return is_bool($e($node['v'] ?? null));
            case 'json_encode':
                return json_encode($e($node['v'] ?? null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            case 'json_decode':
                $decoded = json_decode(Value::str($e($node['v'] ?? '')), true);
                return is_array($decoded) || is_scalar($decoded) || $decoded === null ? $decoded : null;

            // ---------- function call ----------
            case 'call':
                return $this->callFunction($node, $scope);

            default:
                throw new EngineError("unknown expression op \"$op\"");
        }
    }

    // ====================== helpers ======================

    private function callFunction(array $node, Scope $scope): mixed
    {
        $name = $node['name'] ?? '';
        $fn = $this->program->function(is_string($name) ? $name : '');
        if ($fn === null) {
            throw new EngineError("unknown function \"$name\"");
        }
        $args = array_map(fn($a) => $this->evalExpr($a, $scope), $node['args'] ?? []);
        $local = new Scope();
        foreach ($fn['params'] as $i => $param) {
            $local->vars[$param] = $args[$i] ?? null;
        }
        try {
            $this->execList($fn['body'], $local);
        } catch (ReturnSignal $r) {
            return $r->value;
        }
        return null;
    }

    /** 'set.player' → 'player'; 'var.room' → 'room'. */
    private function opScope(string $op): string
    {
        return substr($op, (int)strpos($op, '.') + 1);
    }

    private function varName(array $n, string $field = 'name'): string
    {
        $name = $n[$field] ?? '';
        if (!is_string($name) || $name === '') {
            throw new EngineError("op {$n['op']} needs a \"$field\"");
        }
        return $name;
    }

    /** Evaluates the optional `player` ref field (null → acting player). */
    private function evalPlayerRef(array $n, Scope $scope): ?int
    {
        if (!array_key_exists('player', $n)) {
            return null;
        }
        return $this->ctx->resolvePlayerId($this->evalExpr($n['player'], $scope));
    }

    private function getVar(string $scope, string $name, ?int $playerId, Scope $local): mixed
    {
        return match ($scope) {
            'local' => $local->vars[$name] ?? null,
            'player' => $this->ctx->playerGet($playerId, $name),
            'room' => $this->ctx->roomGet($name),
            'game' => $this->ctx->gameGet($name),
            default => throw new EngineError("bad scope $scope"),
        };
    }

    private function setVar(string $scope, string $name, mixed $value, ?int $playerId, Scope $local): void
    {
        match ($scope) {
            'local' => $local->vars[$name] = $value,
            'player' => $this->ctx->playerSet($playerId, $name, $value),
            'room' => $this->ctx->roomSet($name, $value),
            'game' => $this->ctx->gameSet($name, $value),
            default => throw new EngineError("bad scope $scope"),
        };
    }

    /**
     * Reads a named variable and returns [value, writeBack closure].
     * For list/map mutation statements: {scope, name, player?}.
     */
    private function namedVar(array $n, Scope $scope): array
    {
        $s = $n['scope'] ?? '';
        if (!is_string($s) || !in_array($s, ['local', 'player', 'room', 'game'], true)) {
            throw new EngineError("op {$n['op']} needs scope local|player|room|game");
        }
        $name = $this->varName($n);
        $pid = $this->evalPlayerRef($n, $scope);
        return [
            $this->getVar($s, $name, $pid, $scope),
            fn($v) => $this->setVar($s, $name, $v, $pid, $scope),
        ];
    }
}
