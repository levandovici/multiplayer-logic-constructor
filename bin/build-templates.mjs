// build-templates.mjs — build game templates with real (headless) Blockly.
//
// Each template is authored HERE as Blockly API calls — the script then emits
// two artifacts per template:
//
//   templates/<slug>.workspace.json  — Blockly serialization (editor draft)
//   templates/<slug>.ast.json        — canonical AST via MLC.generateAst
//
// Using the actual block registry + generator means the workspace the user
// sees and the program the engine runs can never drift.
//
//   node bin/build-templates.mjs            # rebuild all
//   node bin/build-templates.mjs clicker    # rebuild one
//
// Requires dev dependency: npm install --save-dev blockly@10.4.3

import Blockly from 'blockly';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'templates');
mkdirSync(OUT, { recursive: true });

globalThis.window = globalThis;
new Function(readFileSync(join(ROOT, 'public_html/js/mlc-blocks.js'), 'utf8'))();
const MLC = globalThis.MLC;
Blockly.defineBlocksWithJsonArray(MLC.BLOCKS);

// ---------- tiny builder DSL ----------
// b(type, fields, inputs) — inputs: name → block (value input) or
// [block,…] (statement input); chain(list) links next-connections.
let WS;
const b = (type, fields = {}, inputs = {}) => {
    const blk = WS.newBlock(type);
    for (const [k, v] of Object.entries(fields)) blk.setFieldValue(v, k);
    for (const [k, v] of Object.entries(inputs)) {
        if (Array.isArray(v)) blk.getInput(k).connection.connect(chain(v).previousConnection);
        else blk.getInput(k).connection.connect(v.outputConnection);
    }
    return blk;
};
const chain = (list) => {
    for (let i = 0; i + 1 < list.length; i++)
        list[i].nextConnection.connect(list[i + 1].previousConnection);
    return list[0];
};
// common expression shortcuts
const num = (v) => b('lit_num', { V: v });
const str = (v) => b('lit_text', { V: v });
const bool = (v) => b('lit_bool', { V: v ? 'TRUE' : 'FALSE' });
const vget = (scope, name) => b('get_var', { SCOPE: scope, NAME: name });
const vset = (scope, name, value) => b('set_var', { SCOPE: scope, NAME: name }, { VALUE: value });
const payload = (key) => b('ex_payload', { KEY: key });
const ctx = (prop) => b('ex_ctx', { PROP: prop });
const cmp = (op, a, x) => b('cmp', { OP: op }, { A: a, B: x });
const math = (op, a, x) => b('math_bin', { OP: op }, { A: a, B: x });
const map = (entries) => b('map_lit', {}, {
    ENTRIES: entries.map(([k, v]) => b('map_entry', { K: k }, { V: v })),
});
const list = (items) => b('list_lit', {}, { ITEMS: items.map((v) => b('list_item', {}, { V: v })) });
const when = (action, body) => {
    const hat = b('ev_player_action', { ACTION: action });
    hat.nextConnection.connect(chain(body).previousConnection);
    return hat;
};

// ---------- templates ----------
const TEMPLATES = {
    tictactoe: {
        name: 'Tic-Tac-Toe',
        genre: 'Board & turn-based',
        desc: 'Turn order, win detection, rejections — the classic.',
        image: '/assets/marketing/genre-board.png',
        variables: { player: { symbol: '' }, room: { board: [0,0,0,0,0,0,0,0,0], turn: 0, winner: '', moves: 0 }, game: {} },
        build() {
            // fn checkWin(s): foreach winning line → if all == s → winner = s
            const lines = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
            const cell = (i) => b('list_get', {}, { LIST: vget('room', 'board'), INDEX: b('list_get', {}, { LIST: vget('local', 'line'), INDEX: num(i) }) });
            const fn = b('fn_def', { NAME: 'checkWin', PARAMS: 's' }, {
                BODY: [b('ctl_foreach', { AS: 'line' }, {
                    LIST: list(lines.map((l) => list(l.map(num)))),
                    BODY: [b('ctl_if', {}, {
                        COND: b('logic_bin', { OP: 'and' }, {
                            A: cmp('eq', cell(0), vget('local', 's')),
                            B: b('logic_bin', { OP: 'and' }, { A: cmp('eq', cell(1), vget('local', 's')), B: cmp('eq', cell(2), vget('local', 's')) }),
                        }),
                        THEN: [vset('room', 'winner', vget('local', 's'))],
                    })],
                })],
            });
            const join = b('ev_player_join');
            join.nextConnection.connect(chain([
                vset('player', 'symbol', b('logic_choose', {}, {
                    COND: cmp('eq', ctx('ctx.player_seat'), num(0)), THEN: str('X'),
                    ELSE: b('logic_choose', {}, { COND: cmp('eq', ctx('ctx.player_seat'), num(1)), THEN: str('O'), ELSE: str('spectator') }),
                })),
                b('send_others', { TYPE: 'player_joined' }, { DATA: map([['name', ctx('ctx.player_name')], ['seat', ctx('ctx.player_seat')]]) }),
            ]).previousConnection);
            when('move', [
                b('ctl_if', {}, { COND: cmp('ne', vget('room', 'winner'), str('')), THEN: [b('act_reject', {}, { REASON: str('game is over') })] }),
                b('ctl_if', {}, { COND: cmp('ne', ctx('ctx.player_seat'), vget('room', 'turn')), THEN: [b('act_reject', {}, { REASON: str('not your turn') })] }),
                vset('local', 'idx', math('add', math('mul', payload('x'), num(3)), payload('y'))),
                b('ctl_if', {}, {
                    COND: cmp('ne', b('list_get', {}, { LIST: vget('room', 'board'), INDEX: vget('local', 'idx') }), num(0)),
                    THEN: [b('act_reject', {}, { REASON: str('cell already taken') })],
                }),
                b('list_set_at', { SCOPE: 'room', NAME: 'board' }, { INDEX: vget('local', 'idx'), VALUE: vget('player', 'symbol') }),
                b('change_var', { SCOPE: 'room', NAME: 'moves' }, { DELTA: num(1) }),
                b('call_fn', { NAME: 'checkWin' }, { ARGS: vget('player', 'symbol') }),
                b('ctl_if', {}, {
                    COND: b('logic_bin', { OP: 'and' }, { A: cmp('eq', vget('room', 'winner'), str('')), B: cmp('eq', vget('room', 'moves'), num(9)) }),
                    THEN: [vset('room', 'winner', str('draw'))],
                }),
                vset('room', 'turn', math('sub', num(1), vget('room', 'turn'))),
                b('send_room', { TYPE: 'board' }, { DATA: map([['board', vget('room', 'board')], ['turn', vget('room', 'turn')], ['winner', vget('room', 'winner')]]) }),
                b('act_respond', {}, { VALUE: map([['ok', bool(true)], ['board', vget('room', 'board')], ['winner', vget('room', 'winner')]]) }),
            ]);
        },
    },

    clicker: {
        name: 'Idle Clicker',
        genre: 'Idle & economy',
        desc: 'Claim resources, buy upgrades — the server owns the math.',
        image: '/assets/marketing/genre-clicker.png',
        variables: { player: { gold: 0, harvest: 1, level: 0 }, room: { upgrades: 0 }, game: {} },
        build() {
            const join = b('ev_player_join');
            join.nextConnection.connect(chain([
                vset('player', 'gold', num(0)), vset('player', 'harvest', num(1)),
                b('send_others', { TYPE: 'player_joined' }, { DATA: map([['name', ctx('ctx.player_name')]]) }),
            ]).previousConnection);
            when('claim', [
                b('ac_ratelimit', {}, { LIMIT: num(2), PER: num(1), KEY: str('claim') }),
                b('change_var', { SCOPE: 'player', NAME: 'gold' }, { DELTA: vget('player', 'harvest') }),
                b('act_respond', {}, { VALUE: map([['gold', vget('player', 'gold')], ['harvest', vget('player', 'harvest')]]) }),
            ]);
            when('upgrade', [
                b('ac_consume', { SCOPE: 'player', NAME: 'gold' }, { AMOUNT: math('mul', num(10), math('add', vget('player', 'level'), num(1))) }),
                b('change_var', { SCOPE: 'player', NAME: 'harvest' }, { DELTA: num(1) }),
                b('change_var', { SCOPE: 'player', NAME: 'level' }, { DELTA: num(1) }),
                b('send_room', { TYPE: 'upgrade' }, { DATA: map([['player', ctx('ctx.player_name')], ['level', vget('player', 'level')]]) }),
                b('act_respond', {}, { VALUE: map([['ok', bool(true)], ['gold', vget('player', 'gold')]]) }),
            ]);
        },
    },

    quiz: {
        name: 'Quiz Night',
        genre: 'Quiz & trivia',
        desc: 'Fastest correct answer wins the points — timing is verified.',
        image: '/assets/marketing/genre-quiz.png',
        variables: { player: { score: 0 }, room: { answer: 'blue', answered: 0, question_no: 0 }, game: {} },
        build() {
            const created = b('ev_room_created');
            created.nextConnection.connect(chain([
                vset('room', 'answer', str('blue')),
                b('send_room', { TYPE: 'question' }, { DATA: map([['q', str('What colour is the sky?')], ['opts', list([str('red'), str('blue'), str('green')])]]) }),
            ]).previousConnection);
            when('answer', [
                b('ctl_if', {}, { COND: cmp('ge', vget('room', 'answered'), num(3)), THEN: [b('act_reject', {}, { REASON: str('question closed') })] }),
                b('ctl_if_else', {}, {
                    COND: cmp('eq', payload('a'), vget('room', 'answer')),
                    THEN: [
                        b('change_var', { SCOPE: 'player', NAME: 'score' }, { DELTA: b('math_bin', { OP: 'sub' }, { A: num(100), B: math('mul', vget('room', 'answered'), num(25)) }) }),
                        b('change_var', { SCOPE: 'room', NAME: 'answered' }, { DELTA: num(1) }),
                        b('send_room', { TYPE: 'score' }, { DATA: map([['player', ctx('ctx.player_name')], ['score', vget('player', 'score')]]) }),
                    ],
                    ELSE: [b('act_reject', {}, { REASON: str('wrong answer') })],
                }),
            ]);
        },
    },

    racing: {
        name: 'Lap Racer',
        genre: 'Racing & laps',
        desc: 'Checkpoints must be hit in order; laps and winner verified.',
        image: '/assets/marketing/genre-racing.png',
        variables: { player: { lap: 0, next_cp: 1 }, room: { total_laps: 3, checkpoints: 4, winner: '' }, game: {} },
        build() {
            when('checkpoint', [
                b('ctl_if', {}, { COND: cmp('ne', vget('room', 'winner'), str('')), THEN: [b('act_reject', {}, { REASON: str('race is over') })] }),
                b('ac_require', {}, { COND: cmp('eq', payload('id'), vget('player', 'next_cp')), REASON: str('wrong checkpoint — hit them in order') }),
                vset('player', 'next_cp', math('add', math('mod', vget('player', 'next_cp'), vget('room', 'checkpoints')), num(1))),
                b('ctl_if', {}, {
                    COND: cmp('eq', payload('id'), vget('room', 'checkpoints')),
                    THEN: [
                        b('change_var', { SCOPE: 'player', NAME: 'lap' }, { DELTA: num(1) }),
                        b('ctl_if', {}, {
                            COND: cmp('ge', vget('player', 'lap'), vget('room', 'total_laps')),
                            THEN: [
                                vset('room', 'winner', ctx('ctx.player_name')),
                                b('ac_match_end', {}, { RESULT: map([['winner', vget('room', 'winner')]]) }),
                            ],
                        }),
                        b('send_room', { TYPE: 'lap' }, { DATA: map([['player', ctx('ctx.player_name')], ['lap', vget('player', 'lap')]]) }),
                    ],
                }),
                b('act_respond', {}, { VALUE: map([['ok', bool(true)], ['lap', vget('player', 'lap')], ['next', vget('player', 'next_cp')]]) }),
            ]);
        },
    },

    cards: {
        name: 'Card Duel',
        genre: 'Card battlers',
        desc: 'Hands stay secret; cards must be held to be played.',
        image: '/assets/marketing/genre-cards.png',
        variables: { player: { hand: [] }, room: { turn: 0, pile: [] }, game: {} },
        build() {
            const join = b('ev_player_join');
            join.nextConnection.connect(chain([
                b('ctl_repeat', {}, { TIMES: num(5), BODY: [
                    b('list_add', { SCOPE: 'player', NAME: 'hand' }, { VALUE: b('math_rand_int', {}, { MIN: num(1), MAX: num(52) }) }),
                ] }),
                b('send_player', { TYPE: 'hand' }, { PLAYER: ctx('ctx.player_id'), DATA: map([['hand', vget('player', 'hand')]]) }),
            ]).previousConnection);
            when('play', [
                b('ctl_if', {}, { COND: cmp('ne', ctx('ctx.player_seat'), vget('room', 'turn')), THEN: [b('act_reject', {}, { REASON: str('not your turn') })] }),
                b('ac_require', {}, {
                    COND: b('list_contains', {}, { LIST: vget('player', 'hand'), VALUE: payload('card') }),
                    REASON: str('you do not hold that card'),
                }),
                b('list_remove_at', { SCOPE: 'player', NAME: 'hand' }, { INDEX: b('list_index_of', {}, { LIST: vget('player', 'hand'), VALUE: payload('card') }) }),
                b('list_add', { SCOPE: 'room', NAME: 'pile' }, { VALUE: payload('card') }),
                vset('room', 'turn', math('sub', num(1), vget('room', 'turn'))),
                b('send_others', { TYPE: 'card_played' }, { DATA: map([['player', ctx('ctx.player_name')], ['card', payload('card')]]) }),
                b('act_respond', {}, { VALUE: map([['ok', bool(true)], ['hand', vget('player', 'hand')]]) }),
            ]);
        },
    },

    arena: {
        name: 'Zone Arena',
        genre: 'Arena & battle',
        desc: 'Shrinking zone on a timer; moves are speed-checked.',
        image: '/assets/marketing/genre-arena.png',
        variables: { player: { x: 0, y: 0, alive: true }, room: { zone: 100, round: 0 }, game: {} },
        build() {
            const start = b('ev_match_start');
            start.nextConnection.connect(b('tm_schedule', { TIMER: 'shrink' }, { DELAY: num(60), PAYLOAD: map([['round', num(1)]]) }).previousConnection);
            const tick = b('ev_timer', { TIMER: 'shrink' });
            tick.nextConnection.connect(chain([
                vset('room', 'zone', math('mul', vget('room', 'zone'), b('lit_num', { V: 0.7 }))),
                b('change_var', { SCOPE: 'room', NAME: 'round' }, { DELTA: num(1) }),
                b('send_room', { TYPE: 'zone' }, { DATA: map([['radius', vget('room', 'zone')], ['round', vget('room', 'round')]]) }),
                b('tm_schedule', { TIMER: 'shrink' }, { DELAY: num(60), PAYLOAD: map([['round', vget('room', 'round')]]) }),
            ]).previousConnection);
            when('move', [
                b('ac_verify_move', {}, {
                    FROM: list([vget('player', 'x'), vget('player', 'y')]),
                    TO: list([payload('x'), payload('y')]),
                    MAX: num(10),
                }),
                vset('player', 'x', payload('x')),
                vset('player', 'y', payload('y')),
                b('send_others', { TYPE: 'moved' }, { DATA: map([['player', ctx('ctx.player_name')], ['x', payload('x')], ['y', payload('y')]]) }),
            ]);
        },
    },
};

// ---------- build ----------
const only = process.argv[2] || null;
let fail = 0;
for (const [slug, t] of Object.entries(TEMPLATES)) {
    if (only && slug !== only) continue;
    try {
        WS = new Blockly.Workspace();
        t.build();
        const wsJson = Blockly.serialization.workspaces.save(WS);
        const ast = MLC.generateAst(WS, t.variables);
        if (!ast.triggers.length) throw new Error('no trigger hat blocks produced');
        writeFileSync(join(OUT, `${slug}.workspace.json`), JSON.stringify(wsJson, null, 2) + '\n');
        writeFileSync(join(OUT, `${slug}.ast.json`), JSON.stringify(ast, null, 2) + '\n');
        console.log(`ok ${slug} — ${ast.triggers.length} triggers, ${ast.functions.length} fns`);
    } catch (e) { console.error(`FAIL ${slug}: ${e.message}`); fail++; }
}
console.log(fail ? `${fail} failed` : 'all templates built');
process.exit(fail ? 1 : 0);
