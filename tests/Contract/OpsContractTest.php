<?php

namespace Mlc\Tests\Contract;

use Mlc\Engine\Ops;
use Mlc\Engine\Program;
use PHPUnit\Framework\TestCase;

// Contract guard: the three places that know the op vocabulary —
// Ops.php (whitelist), Executor.php (semantics), mlc-blocks.js (editor) —
// must never drift apart. Plus AST-shape invariants the API relies on.
class OpsContractTest extends TestCase
{
    private const JS_PATH = __DIR__ . '/../../public_html/js/mlc-blocks.js';

    /** Every op the JS generator can emit exists in the engine whitelist. */
    public function testEditorOpsAreWhitelisted(): void
    {
        $js = file_get_contents(self::JS_PATH);
        $this->assertNotFalse($js, 'mlc-blocks.js missing');
        preg_match_all("/\{\s*op:\s*'([^']+)'/", $js, $m);
        // dynamic ops:  op: 'set.' + scopeField(b)  → capture the prefix
        preg_match_all("/op:\s*'([a-z_]+)\.'\s*\+/", $js, $m2);

        $emitted = array_unique($m[1]);
        $prefixes = array_unique($m2[1]);
        $this->assertNotEmpty($emitted, 'no ops found in mlc-blocks.js — generator changed shape?');
        // 'set.'-style captures are dynamic prefixes — validated separately.
        $emitted = array_filter($emitted, fn($o) => !str_ends_with($o, '.'));

        $whitelist = array_merge(Ops::STATEMENTS, Ops::EXPRESSIONS);
        foreach (array_diff($emitted, $whitelist) as $u) {
            $this->fail("editor emits op '$u' not present in Ops whitelist");
        }
        // a prefix must produce a whitelisted op for every scope
        foreach ($prefixes as $p) {
            foreach (['local', 'player', 'room', 'game'] as $scope) {
                $this->assertTrue(
                    Ops::isKnown("$p.$scope"),
                    "editor emits dynamic op prefix '$p.' but '$p.$scope' is not whitelisted"
                );
            }
        }
    }

    /** Dynamic 'X.'+scope ops resolve to whitelisted names. */
    public function testDynamicScopedOpsResolve(): void
    {
        foreach (['set', 'change', 'var'] as $prefix) {
            foreach (['local', 'player', 'room', 'game'] as $scope) {
                $this->assertTrue(
                    Ops::isKnown("$prefix.$scope"),
                    "missing whitelisted op $prefix.$scope"
                );
            }
        }
    }

    /** The JS generator's event names match the engine's event table. */
    public function testEditorEventsMatchEngine(): void
    {
        $js = file_get_contents(self::JS_PATH);
        // Only the EVENT_OPS map maps block types → engine event names.
        $this->assertMatchesRegularExpression('/const EVENT_OPS = \{(.*?)\};/s', $js);
        preg_match('/const EVENT_OPS = \{(.*?)\};/s', $js, $map);
        preg_match_all("/ev_\w+:\s*'([a-z_]+)'/", $map[1], $m);
        $events = array_unique($m[1]);
        $this->assertNotEmpty($events);
        foreach ($events as $e) {
            $this->assertTrue(Ops::isEvent($e), "editor event '$e' unknown to engine");
        }
    }

    /** No overlap surprises: statement and expression sets are disjoint
     *  except 'call' which is deliberately both (value + effect). */
    public function testStatementExpressionOverlapIsIntentional(): void
    {
        $both = array_intersect(Ops::STATEMENTS, Ops::EXPRESSIONS);
        $this->assertSame(['call'], array_values($both), 'only call may be both');
    }

    /** Every whitelisted op appears in the Executor's dispatch tables —
     *  catches "whitelisted but unimplemented". */
    public function testAllOpsHaveExecutorBranches(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Engine/Executor.php');
        $this->assertNotFalse($src);
        foreach (array_merge(Ops::STATEMENTS, Ops::EXPRESSIONS) as $op) {
            $found = str_contains($src, "'$op'") || str_contains($src, "\"$op\"");
            $this->assertTrue($found, "op '$op' whitelisted but not referenced in Executor");
        }
    }

    /** Canonical fixture stays valid + the trigger table round-trips. */
    public function testFixtureDescribesCleanly(): void
    {
        $program = Program::fromJson(
            file_get_contents(dirname(__DIR__) . '/Fixtures/programs/tictactoe.json')
        );
        $desc = $program->describe();
        $this->assertSame(1, $desc['version']);
        $events = array_column($desc['triggers'], 'event');
        $this->assertContains('on_player_join', $events);
        $this->assertContains('on_player_action', $events);
        $this->assertSame(['checkWin'], array_column($desc['functions'], 'name'));
    }
}
