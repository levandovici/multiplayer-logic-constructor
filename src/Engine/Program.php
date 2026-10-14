<?php

namespace Mlc\Engine;

// A compiled, validated block program (canonical AST). Immutable once
// loaded — published program_versions rows deserialize straight into this.
//
// Canonical shape:
// {
//   "version": 1,
//   "variables": { "player": {...}, "room": {...}, "game": {...} },
//   "functions": [ {"name": "f", "params": ["a"], "body": [stmt...]} ],
//   "triggers":  [ {"event": "on_player_action", "action": "move",
//                   "body": [stmt...]} ]
// }
final class Program
{
    public const MAX_DEPTH = 64;
    public const MAX_NODES = 5000;

    /** @var list<array{event:string,key:?string,body:list<array>}> */
    private array $triggers = [];
    /** @var array<string,array{name:string,params:list<string>,body:list<array>}> */
    private array $functions = [];
    /** @var array{player:array,room:array,game:array} */
    private array $defaults = ['player' => [], 'room' => [], 'game' => []];

    private function __construct(private array $ast)
    {
    }

    /**
     * Parse + validate a canonical program. Throws EngineError on malformed
     * structure — callers (publish flow) surface the message to the developer.
     */
    public static function load(array $ast): self
    {
        $p = new self($ast);

        $version = $ast['version'] ?? 1;
        if ($version !== 1) {
            throw new EngineError("unsupported program version: " . json_encode($version));
        }

        foreach (['player', 'room', 'game'] as $scope) {
            $defaults = $ast['variables'][$scope] ?? [];
            if (!is_array($defaults)) {
                throw new EngineError("variables.$scope must be a map");
            }
            $p->defaults[$scope] = $defaults;
        }

        $nodeCount = 0;
        foreach (($ast['functions'] ?? []) as $i => $fn) {
            if (!is_array($fn) || !is_string($fn['name'] ?? null) || !is_array($fn['body'] ?? null)) {
                throw new EngineError("functions[$i] needs {name, body[]}");
            }
            if ($fn['name'] === '' || isset($p->functions[$fn['name']])) {
                throw new EngineError("duplicate or empty function name at functions[$i]");
            }
            $params = $fn['params'] ?? [];
            if (!is_array($params) || array_filter($params, 'is_string') !== $params) {
                throw new EngineError("function {$fn['name']} params must be strings");
            }
            $p->validateList($fn['body'], 0, $nodeCount);
            $p->functions[$fn['name']] = [
                'name' => $fn['name'],
                'params' => array_values($params),
                'body' => $fn['body'],
            ];
        }

        foreach (($ast['triggers'] ?? []) as $i => $t) {
            if (!is_array($t) || !is_string($t['event'] ?? null) || !is_array($t['body'] ?? null)) {
                throw new EngineError("triggers[$i] needs {event, body[]}");
            }
            if (!Ops::isEvent($t['event'])) {
                throw new EngineError("triggers[$i] unknown event {$t['event']}");
            }
            $keyField = Ops::eventKeyField($t['event']);
            $key = $keyField !== null ? ($t[$keyField] ?? null) : null;
            if ($keyField !== null && (!is_string($key) || $key === '')) {
                throw new EngineError("triggers[$i] {$t['event']} requires \"$keyField\"");
            }
            $p->validateList($t['body'], 0, $nodeCount);
            $p->triggers[] = ['event' => $t['event'], 'key' => $key, 'body' => $t['body']];
        }

        if ($nodeCount > self::MAX_NODES) {
            throw new EngineError("program too large ($nodeCount nodes, max " . self::MAX_NODES . ")");
        }
        return $p;
    }

    public static function fromJson(string $json): self
    {
        $ast = json_decode($json, true);
        if (!is_array($ast)) {
            throw new EngineError('program is not valid JSON');
        }
        return self::load($ast);
    }

    /** Triggers matching an event (+ key for keyed events), in order. */
    public function triggersFor(string $event, ?string $key): array
    {
        return array_values(array_filter(
            $this->triggers,
            fn($t) => $t['event'] === $event
                && (Ops::eventKeyField($event) === null || $t['key'] === $key)
        ));
    }

    public function function(string $name): ?array
    {
        return $this->functions[$name] ?? null;
    }

    /** Declared variable defaults for a persistent scope. */
    public function defaults(string $scope): array
    {
        return $this->defaults[$scope] ?? [];
    }

    /** Full defaults map for Context construction. */
    public function defaultsAll(): array
    {
        return $this->defaults;
    }

    /** Trigger table for api/logic.php?action=meta + the editor. */
    public function describe(): array
    {
        return [
            'version' => $this->ast['version'] ?? 1,
            'triggers' => array_map(
                fn($t) => ['event' => $t['event'], 'key' => $t['key']],
                $this->triggers
            ),
            'functions' => array_values(array_map(
                fn($f) => ['name' => $f['name'], 'params' => $f['params']],
                $this->functions
            )),
        ];
    }

    // ---------------- validation ----------------

    private function validateList(array $stmts, int $depth, int &$count): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new EngineError('program nesting too deep');
        }
        foreach ($stmts as $node) {
            $this->validateNode($node, $depth, $count, true);
        }
    }

    private function validateNode(mixed $node, int $depth, int &$count, bool $statement): void
    {
        $count++;
        if (!is_array($node) || !is_string($node['op'] ?? null)) {
            throw new EngineError('every node needs a string "op"');
        }
        $op = $node['op'];
        if (!Ops::isKnown($op)) {
            throw new EngineError("unknown op \"$op\"");
        }
        if ($statement && !Ops::isStatement($op)) {
            // an expression where a statement is required
            throw new EngineError("op \"$op\" is an expression, not a statement");
        }
        foreach ($node as $field => $v) {
            if (in_array($field, ['op', 'name', 'scope', 'as', 'index'], true)) {
                continue; // block parameters, not code
            }
            if (is_array($v)) {
                if ($v !== [] && isset($v['op'])) {
                    $this->validateNode($v, $depth + 1, $count, false);
                } elseif (in_array($field, ['then', 'else', 'body'], true)) {
                    $this->validateList($v, $depth + 1, $count);
                } else {
                    // Literal arrays (list/map literals, args lists, entry
                    // pairs) — deep-scan for nested op nodes; scalars and
                    // sub-arrays without an "op" pass through untouched.
                    $this->scanLiterals($v, $depth + 1, $count);
                }
            }
        }
    }

    /** Deep-scan a literal array field: validate any element carrying "op". */
    private function scanLiterals(array $items, int $depth, int &$count): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['op']) && is_string($item['op'])) {
                $this->validateNode($item, $depth, $count, false);
            } else {
                $this->scanLiterals($item, $depth, $count);
            }
        }
    }
}
