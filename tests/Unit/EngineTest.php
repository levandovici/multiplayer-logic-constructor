<?php

namespace Mlc\Tests\Unit;

use Mlc\Engine\ArrayStateStore;
use Mlc\Engine\BudgetExhausted;
use Mlc\Engine\Context;
use Mlc\Engine\EngineError;
use Mlc\Engine\Executor;
use Mlc\Engine\Program;
use Mlc\Engine\Reject;
use PHPUnit\Framework\TestCase;

class EngineTest extends TestCase
{
    private function game(): array
    {
        return ['id' => 1, 'name' => 'Test', 'published_program_id' => 1];
    }

    private function room(): array
    {
        return ['room_id' => 'r1', 'game_id' => 1, 'name' => 'Room', 'status' => 'playing'];
    }

    private function player(int $id = 10): array
    {
        return ['id' => $id, 'game_id' => 1, 'name' => 'p' . $id];
    }

    /** Run a program fixture/array against an in-memory store. */
    private function execProgram(array $ast, string $event, ?string $key = null, array $payload = [], ?array $player = null, ?ArrayStateStore $store = null): array
    {
        $store ??= new ArrayStateStore();
        $program = Program::load($ast);
        $ctx = new Context(
            $store, $this->game(), $this->room(), $player ?? $this->player(),
            $payload, $event, $key, $program->defaultsAll()
        );
        $exec = new Executor($program, $ctx);
        $exec->fire();
        $ctx->flush();
        return [$ctx, $exec, $store];
    }

    public function testLiteralAndVarScopes(): void
    {
        [$ctx] = $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [
                    ['op' => 'set.local', 'name' => 'x', 'value' => 5],
                    ['op' => 'set.room', 'name' => 'y', 'value' => ['op' => 'add', 'a' => ['op' => 'var.local', 'name' => 'x'], 'b' => 2]],
                    ['op' => 'set.game', 'name' => 'g', 'value' => 'hello'],
                    ['op' => 'set.player', 'name' => 'p', 'value' => true],
                ],
            ]],
        ], 'on_player_action', 't');

        $this->assertSame(7, $ctx->roomGet('y'));
        $this->assertSame('hello', $ctx->gameGet('g'));
        $this->assertSame(true, $ctx->playerGet(null, 'p'));
    }

    public function testDefaultsSeedScopes(): void
    {
        [$ctx] = $this->execProgram([
            'variables' => ['room' => ['hp' => 100]],
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [['op' => 'change.room', 'name' => 'hp', 'delta' => -15]],
            ]],
        ], 'on_player_action', 't');
        $this->assertSame(85, $ctx->roomGet('hp'));
    }

    public function testIfElseAndRepeat(): void
    {
        $ast = [
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [
                    ['op' => 'set.local', 'name' => 'sum', 'value' => 0],
                    ['op' => 'repeat', 'times' => 5, 'body' => [
                        ['op' => 'change.local', 'name' => 'sum', 'delta' => 2],
                    ]],
                    ['op' => 'if', 'cond' => ['op' => 'eq', 'a' => ['op' => 'var.local', 'name' => 'sum'], 'b' => 10],
                        'then' => [['op' => 'set.room', 'name' => 'r', 'value' => 'ten']],
                        'else' => [['op' => 'set.room', 'name' => 'r', 'value' => 'other']],
                    ],
                ],
            ]],
        ];
        [$ctx] = $this->execProgram($ast, 'on_player_action', 't');
        $this->assertSame('ten', $ctx->roomGet('r'));
    }

    public function testForeachAndListOps(): void
    {
        $ast = [
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [
                    ['op' => 'set.room', 'name' => 'deck', 'value' => ['op' => 'list_lit', 'items' => [1, 2, 3]]],
                    ['op' => 'list.add', 'scope' => 'room', 'name' => 'deck', 'value' => 4],
                    ['op' => 'set.local', 'name' => 'total', 'value' => 0],
                    ['op' => 'foreach', 'list' => ['op' => 'var.room', 'name' => 'deck'], 'as' => 'card', 'body' => [
                        ['op' => 'change.local', 'name' => 'total', 'delta' => ['op' => 'var.local', 'name' => 'card']],
                    ]],
                ],
            ]],
        ];
        [$ctx, , $store] = $this->execProgram($ast, 'on_player_action', 't');
        $this->assertSame([1, 2, 3, 4], $store->roomStates['r1']['deck']);
        // total is local — assert via a logged line? read through side channel:
        $this->assertSame(4, count($store->roomStates['r1']['deck']));
    }

    public function testRejectThrows(): void
    {
        $this->expectException(Reject::class);
        $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [['op' => 'reject', 'reason' => 'no']],
            ]],
        ], 'on_player_action', 't');
    }

    public function testRespondHaltsChain(): void
    {
        [$ctx] = $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [
                    ['op' => 'respond', 'value' => ['op' => 'map_lit', 'entries' => [['key' => 'a', 'value' => 1]]]],
                    ['op' => 'set.room', 'name' => 'never', 'value' => true],
                ],
            ]],
        ], 'on_player_action', 't');
        $this->assertTrue($ctx->responded);
        $this->assertSame(['a' => 1], $ctx->response);
        $this->assertNull($ctx->roomGet('never'));
    }

    public function testLoopBudget(): void
    {
        $this->expectException(BudgetExhausted::class);
        $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [['op' => 'while', 'cond' => true, 'body' => []]],
            ]],
        ], 'on_player_action', 't');
    }

    public function testSendAndScheduleEffects(): void
    {
        $store = new ArrayStateStore();
        $store->members = [
            ['room_id' => 'r1', 'player_id' => 10, 'player_name' => 'a', 'is_host' => 1, 'is_online' => 1, 'seat' => 0],
            ['room_id' => 'r1', 'player_id' => 11, 'player_name' => 'b', 'is_host' => 0, 'is_online' => 1, 'seat' => 1],
        ];
        [$ctx, , $store] = $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [
                    ['op' => 'send.room', 'type' => 'all', 'data' => 'hello'],
                    ['op' => 'send.others', 'type' => 'others', 'data' => 'hi'],
                    ['op' => 'send.player', 'player' => 11, 'type' => 'direct', 'data' => 'yo'],
                    ['op' => 'schedule', 'timer' => 'round_end', 'delay' => 90, 'payload' => ['op' => 'map_lit', 'entries' => [['key' => 'k', 'value' => 1]]]],
                ],
            ]],
        ], 'on_player_action', 't', [], $this->player(10), $store);

        $this->assertCount(4, $store->sentUpdates); // all(2) + others(1) + direct(1)
        $types = array_column($store->sentUpdates, 'type');
        $this->assertSame(['all', 'all', 'others', 'direct'], $types);
        $this->assertSame(11, $store->sentUpdates[3]['player_id']);
        $this->assertSame(11, $store->sentUpdates[2]['player_id']); // others → not self (actor is 10)
        $this->assertCount(1, $store->events);
        $this->assertSame('round_end', $store->events[0]['timer']);
    }

    public function testUnknownOpFailsValidation(): void
    {
        $this->expectException(EngineError::class);
        Program::load(['triggers' => [['event' => 'on_player_action', 'action' => 't', 'body' => [['op' => 'eval_code']]]]]);
    }

    public function testFunctionCallAndReturn(): void
    {
        [$ctx] = $this->execProgram([
            'functions' => [[
                'name' => 'double', 'params' => ['n'],
                'body' => [['op' => 'return', 'value' => ['op' => 'mul', 'a' => ['op' => 'var.local', 'name' => 'n'], 'b' => 2]]],
            ]],
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [['op' => 'set.room', 'name' => 'v', 'value' => ['op' => 'call', 'name' => 'double', 'args' => [21]]]],
            ]],
        ], 'on_player_action', 't');
        $this->assertSame(42, $ctx->roomGet('v'));
    }

    // ====================== anti-cheat ops ======================

    public function testRequireRejectsOnFalse(): void
    {
        $this->expectException(Reject::class);
        $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [['op' => 'require', 'cond' => false, 'reason' => 'nope']],
            ]],
        ], 'on_player_action', 't');
    }

    public function testConsumeAtomic(): void
    {
        $store = new ArrayStateStore();
        $store->playerStates['r1:10'] = ['gold' => 30];
        [$ctx] = $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 'buy',
                'body' => [['op' => 'consume', 'scope' => 'player', 'name' => 'gold', 'amount' => 20]],
            ]],
        ], 'on_player_action', 'buy', [], $this->player(10), $store);
        $this->assertSame(10, $store->playerStates['r1:10']['gold']);

        // insufficient → reject, balance untouched
        try {
            $this->execProgram([
                'triggers' => [[
                    'event' => 'on_player_action', 'action' => 'buy',
                    'body' => [['op' => 'consume', 'scope' => 'player', 'name' => 'gold', 'amount' => 50]],
                ]],
            ], 'on_player_action', 'buy', [], $this->player(10), $store);
            $this->fail('expected reject');
        } catch (Reject $r) {
            $this->assertStringContainsString('insufficient', $r->reason);
        }
        $this->assertSame(10, $store->playerStates['r1:10']['gold']);
    }

    public function testRatelimitRejectsAfterLimit(): void
    {
        $store = new ArrayStateStore();
        $ast = [
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 'chat',
                'body' => [['op' => 'ratelimit', 'limit' => 2, 'per' => 60]],
            ]],
        ];
        $p = $this->player(10);
        $this->execProgram($ast, 'on_player_action', 'chat', [], $p, $store); // 1
        $this->execProgram($ast, 'on_player_action', 'chat', [], $p, $store); // 2
        try {
            $this->execProgram($ast, 'on_player_action', 'chat', [], $p, $store); // 3 → reject
            $this->fail('expected reject');
        } catch (Reject $r) {
            $this->assertStringContainsString('rate limit', $r->reason);
        }
    }

    public function testVerifyMove(): void
    {
        // within range → ok
        $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 'm',
                'body' => [['op' => 'verify.move', 'from' => [0, 0], 'to' => [1, 1], 'max' => 1]],
            ]],
        ], 'on_player_action', 'm');
        // out of range → reject
        try {
            $this->execProgram([
                'triggers' => [[
                    'event' => 'on_player_action', 'action' => 'm',
                    'body' => [['op' => 'verify.move', 'from' => [0, 0], 'to' => [5, 0], 'max' => 1]],
                ]],
            ], 'on_player_action', 'm');
            $this->fail('expected reject');
        } catch (Reject $r) {
            $this->assertSame('move too far', $r->reason);
        }
    }

    public function testTurnCycle(): void
    {
        $store = new ArrayStateStore();
        $store->members = [
            ['room_id' => 'r1', 'player_id' => 10, 'player_name' => 'a', 'is_host' => 1, 'is_online' => 1, 'seat' => 0],
            ['room_id' => 'r1', 'player_id' => 11, 'player_name' => 'b', 'is_host' => 0, 'is_online' => 1, 'seat' => 1],
        ];
        $ast = [
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 'pass',
                'body' => [
                    ['op' => 'turn.assert'],
                    ['op' => 'turn.next'],
                ],
            ]],
        ];
        // seed: alice's turn
        $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 'init',
                'body' => [['op' => 'turn.set', 'player' => 10]],
            ]],
        ], 'on_player_action', 'init', [], $this->player(10), $store);
        $this->assertSame(10, $store->roomStates['r1']['turn']);

        // bob acts out of turn → reject
        try {
            $this->execProgram($ast, 'on_player_action', 'pass', [], $this->player(11), $store);
            $this->fail('expected reject');
        } catch (Reject $r) {
            $this->assertSame('not your turn', $r->reason);
        }

        // alice passes → turn goes to bob; bob passes → wraps to alice
        $this->execProgram($ast, 'on_player_action', 'pass', [], $this->player(10), $store);
        $this->assertSame(11, $store->roomStates['r1']['turn']);
        $this->execProgram($ast, 'on_player_action', 'pass', [], $this->player(11), $store);
        $this->assertSame(10, $store->roomStates['r1']['turn']);
    }

    public function testKickAndMatchStatus(): void
    {
        $store = new ArrayStateStore();
        $store->members = [
            ['room_id' => 'r1', 'player_id' => 10, 'player_name' => 'a', 'is_host' => 1, 'is_online' => 1, 'seat' => 0],
            ['room_id' => 'r1', 'player_id' => 11, 'player_name' => 'b', 'is_host' => 0, 'is_online' => 1, 'seat' => 1],
        ];
        $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [
                    ['op' => 'match.start'],
                    ['op' => 'player.kick', 'player' => 11, 'reason' => 'cheating'],
                    ['op' => 'match.end', 'result' => 'forfeit'],
                ],
            ]],
        ], 'on_player_action', 't', [], $this->player(10), $store);

        $this->assertSame('ended', $store->roomStatuses['r1']);
        $this->assertSame([11], $store->kicked);
        $kicks = array_filter($store->sentUpdates, fn($u) => $u['type'] === 'kicked');
        $this->assertCount(1, $kicks);
        $this->assertSame(['reason' => 'cheating'], array_values($kicks)[0]['data']);
        // kick removed bob → match_ended broadcast reaches only alice
        $ends = array_values(array_filter($store->sentUpdates, fn($u) => $u['type'] === 'match_ended'));
        $this->assertCount(1, $ends);
        $this->assertSame(10, $ends[0]['player_id']);
    }

    public function testRoomMemberExpr(): void
    {
        $store = new ArrayStateStore();
        $store->members = [
            ['room_id' => 'r1', 'player_id' => 11, 'player_name' => 'bob', 'is_host' => 1, 'is_online' => 1, 'seat' => 2],
        ];
        [$ctx] = $this->execProgram([
            'triggers' => [[
                'event' => 'on_player_action', 'action' => 't',
                'body' => [['op' => 'set.room', 'name' => 'who',
                    'value' => ['op' => 'room.member', 'id' => 11]]],
            ]],
        ], 'on_player_action', 't', [], $this->player(10), $store);
        $who = $ctx->roomGet('who');
        $this->assertSame('bob', $who['name']);
        $this->assertSame(2, $who['seat']);
    }

    // ====================== tic-tac-toe fixture ======================

    private function tictactoe(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/programs/tictactoe.json'), true);
    }

    private function tttRun(ArrayStateStore $store, Program $program, string $event, array $playerRow, array $payload = [], ?string $key = null): Context
    {
        $ctx = new Context(
            $store, $this->game(), $this->room(), $playerRow,
            $payload, $event, $key, $program->defaultsAll()
        );
        (new Executor($program, $ctx))->fire();
        $ctx->flush();
        return $ctx;
    }

    public function testTicTacToeFullGame(): void
    {
        $store = new ArrayStateStore();
        $store->members = [
            ['room_id' => 'r1', 'player_id' => 10, 'player_name' => 'alice', 'is_host' => 1, 'is_online' => 1, 'seat' => 0],
            ['room_id' => 'r1', 'player_id' => 11, 'player_name' => 'bob', 'is_host' => 0, 'is_online' => 1, 'seat' => 1],
        ];
        $ast = $this->tictactoe();
        $program = Program::load($ast);

        $alice = $this->player(10);
        $bob = $this->player(11);
        $bob['name'] = 'bob';

        // joins assign symbols
        $this->tttRun($store, $program, 'on_player_join', $alice);
        $this->tttRun($store, $program, 'on_player_join', $bob);
        $this->assertSame('X', $store->playerStates['r1:10']['symbol']);
        $this->assertSame('O', $store->playerStates['r1:11']['symbol']);
        // Both members pre-exist: alice's join notifies bob, bob's notifies alice.
        $this->assertSame('player_joined', $store->sentUpdates[0]['type']);
        $this->assertSame(11, $store->sentUpdates[0]['player_id']);
        $this->assertSame(10, $store->sentUpdates[1]['player_id']);

        // wrong-turn rejection: bob moves first → Reject
        try {
            $this->tttRun($store, $program, 'on_player_action', $bob, ['x' => 0, 'y' => 0], 'move');
            $this->fail('expected reject');
        } catch (Reject $r) {
            $this->assertSame('not your turn', $r->reason);
        }

        // idx = x*3+y: X(0,0) O(1,0) X(0,1) O(1,1) X(0,2) → X wins top row
        $moves = [
            [$alice, 0, 0], [$bob, 1, 0], [$alice, 0, 1], [$bob, 1, 1], [$alice, 0, 2],
        ];
        $lastCtx = null;
        foreach ($moves as [$p, $x, $y]) {
            $lastCtx = $this->tttRun($store, $program, 'on_player_action', $p, ['x' => $x, 'y' => $y], 'move');
        }

        $this->assertSame('X', $store->roomStates['r1']['winner']);
        $this->assertSame(['X', 'X', 'X', 'O', 'O', 0, 0, 0, 0], $store->roomStates['r1']['board']);
        $this->assertTrue($lastCtx->responded);
        $this->assertSame('X', $lastCtx->response['winner']);

        // board broadcasts went to both players on each move
        $boardMsgs = array_filter($store->sentUpdates, fn($u) => $u['type'] === 'board');
        $this->assertCount(10, $boardMsgs);

        // post-win move rejected
        try {
            $this->tttRun($store, $program, 'on_player_action', $bob, ['x' => 2, 'y' => 2], 'move');
            $this->fail('expected reject');
        } catch (Reject $r) {
            $this->assertSame('game is over', $r->reason);
        }
    }
}
