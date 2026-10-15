// MLC block definitions + canonical-AST generator.
// Block field/input names map 1:1 onto AST node fields — the generator
// walks the workspace and emits the same JSON the PHP engine validates.
(function () {
    'use strict';

    const C = {           // block colours (hue)
        event: 45, var: 300, control: 120, action: 160,
        comm: 200, timer: 260, fn: 290, expr: 210,
        math: 230, logic: 190, text: 160, list: 260, map: 30,
    };

    const SCOPES = [['local', 'local'], ['player', 'player'], ['room', 'room'], ['game', 'game']];

    function hat(type, label, fields, extras) {
        return Object.assign({
            type, message0: label, colour: C.event,
            nextStatement: null, inputsInline: true,
        }, fields ? { args0: fields } : {}, extras || {});
    }

    function stmt(type, message, args) {
        return {
            type, message0: message, args0: args || [],
            previousStatement: null, nextStatement: null,
            inputsInline: true, colour: C.control,
        };
    }

    function expr(type, message, args, colour) {
        return {
            type, message0: message, args0: args || [],
            output: null, inputsInline: true, colour: colour || C.expr,
        };
    }

    const val = (name, check) => ({ type: 'input_value', name, check });
    const field = (name, text) => ({ type: 'field_input', name, text });
    const drop = (name, opts) => ({ type: 'field_dropdown', name, options: opts });
    const stmts = (name) => ({ type: 'input_statement', name });

    const BLOCKS = [
        // ============ events (hat blocks) ============
        hat('ev_player_action', 'when player action %1', [field('ACTION', 'move')]),
        hat('ev_message', 'when message %1', [field('MESSAGE', 'chat')]),
        hat('ev_timer', 'every timer %1 fires', [field('TIMER', 'round_end')]),
        hat('ev_player_join', 'when player joins'),
        hat('ev_player_leave', 'when player leaves'),
        hat('ev_room_created', 'when room is created'),
        hat('ev_match_start', 'when match starts'),
        hat('ev_match_end', 'when match ends'),

        // ============ variables ============
        stmt('set_var', 'set %1 %2 = %3', [drop('SCOPE', SCOPES), field('NAME', 'score'), val('VALUE')]),
        stmt('change_var', 'change %1 %2 by %3', [drop('SCOPE', SCOPES), field('NAME', 'score'), val('DELTA')]),
        expr('get_var', '%1 %2', [drop('SCOPE', SCOPES), field('NAME', 'score')], C.var),

        // ============ control flow ============
        stmt('ctl_if', 'if %1 %2', [val('COND', 'Boolean'), stmts('THEN')]),
        Object.assign(stmt('ctl_if_else', 'if %1 %2 else %3',
            [val('COND', 'Boolean'), stmts('THEN'), stmts('ELSE')])),
        stmt('ctl_repeat', 'repeat %1 times %2', [val('TIMES', 'Number'), stmts('BODY')]),
        stmt('ctl_while', 'while %1 %2', [val('COND', 'Boolean'), stmts('BODY')]),
        stmt('ctl_repeat_until', 'repeat until %1 %2', [val('COND', 'Boolean'), stmts('BODY')]),
        stmt('ctl_foreach', 'for each %2 in %1 %3', [val('LIST'), field('AS', 'item'), stmts('BODY')]),
        stmt('ctl_foreach_player', 'for each player as %1 %2', [field('AS', 'p'), stmts('BODY')]),
        stmt('ctl_break', 'break out of loop'),

        // ============ functions ============
        Object.assign(stmt('fn_def', 'define %1 ( %2 ) %3',
            [field('NAME', 'checkWin'), field('PARAMS', 'a, b'), stmts('BODY')],
        ), { previousStatement: null, nextStatement: null, colour: C.fn, inputsInline: true }),
        stmt('call_fn', 'call %1 args %2', [field('NAME', 'checkWin'), val('ARGS')]),
        Object.assign(expr('call_fn_ret', 'call %1 args %2 → value',
            [field('NAME', 'checkWin'), val('ARGS')]), { colour: C.fn }),
        stmt('return_stmt', 'return %1', [val('VALUE')]),

        // ============ action outcome ============
        Object.assign(stmt('act_respond', 'respond %1', [val('VALUE')]), { colour: C.action }),
        Object.assign(stmt('act_reject', 'reject action: %1', [val('REASON')]), { colour: C.action }),
        Object.assign(stmt('act_log', 'log %1', [val('MESSAGE')]), { colour: C.action }),

        // ============ communication ============
        Object.assign(stmt('send_player', 'send to player %1 type %2 data %3',
            [val('PLAYER'), field('TYPE', 'update'), val('DATA')]), { colour: C.comm }),
        Object.assign(stmt('send_room', 'send to room type %1 data %2',
            [field('TYPE', 'state_changed'), val('DATA')]), { colour: C.comm }),
        Object.assign(stmt('send_others', 'send to others type %1 data %2',
            [field('TYPE', 'state_changed'), val('DATA')]), { colour: C.comm }),

        // ============ timers ============
        Object.assign(stmt('tm_schedule', 'schedule timer %1 in %2 s payload %3',
            [field('TIMER', 'round_end'), val('DELAY', 'Number'), val('PAYLOAD')]), { colour: C.timer }),
        Object.assign(stmt('tm_cancel', 'cancel timer %1', [field('TIMER', 'round_end')]), { colour: C.timer }),

        // ============ anti-cheat ============
        Object.assign(stmt('ac_require', 'require %1 else reject %2',
            [val('COND', 'Boolean'), val('REASON')]), { colour: 0 }),
        Object.assign(stmt('ac_consume', 'consume %3 of %1 %2',
            [drop('SCOPE', SCOPES), field('NAME', 'gold'), val('AMOUNT')]), { colour: 0 }),
        Object.assign(stmt('ac_ratelimit', 'rate limit %1 calls per %2 s (key %3)',
            [val('LIMIT', 'Number'), val('PER', 'Number'), val('KEY')]), { colour: 0 }),
        Object.assign(stmt('ac_verify_move', 'verify move from %1 to %2 max %3 per cell',
            [val('FROM'), val('TO'), val('MAX')]), { colour: 0 }),
        Object.assign(stmt('ac_turn_assert', 'assert it is my turn'), { colour: 0 }),
        Object.assign(stmt('ac_turn_set', 'set turn to player %1', [val('PLAYER')]), { colour: 0 }),
        Object.assign(stmt('ac_turn_next', 'advance turn to next player'), { colour: 0 }),
        Object.assign(stmt('ac_kick', 'kick player %1 reason %2', [val('PLAYER'), val('REASON')]), { colour: 0 }),
        Object.assign(stmt('ac_match_start', 'match start, broadcast %1', [val('DATA')]), { colour: 0 }),
        Object.assign(stmt('ac_match_end', 'match end, result %1', [val('RESULT')]), { colour: 0 }),
        Object.assign(stmt('ac_broadcast_log', 'broadcast log %1', [val('MESSAGE')]), { colour: 0 }),
        expr('ex_turn_current', 'current turn player', [], 0),
        expr('ex_room_member', 'room member %1', [val('ID')], 0),

        // ============ named list/map mutation ============
        stmt('list_add', 'add %3 to list %1 %2', [drop('SCOPE', SCOPES), field('NAME', 'items'), val('VALUE')]),
        stmt('list_insert_at', 'insert %4 at %3 of list %1 %2', [drop('SCOPE', SCOPES), field('NAME', 'items'), val('INDEX'), val('VALUE')]),
        stmt('list_set_at', 'set item %3 of list %1 %2 to %4', [drop('SCOPE', SCOPES), field('NAME', 'items'), val('INDEX'), val('VALUE')]),
        stmt('list_remove_at', 'remove item %3 of list %1 %2', [drop('SCOPE', SCOPES), field('NAME', 'items'), val('INDEX')]),
        stmt('list_clear', 'clear list %1 %2', [drop('SCOPE', SCOPES), field('NAME', 'items')]),
        stmt('map_set', 'set key %3 of map %1 %2 to %4', [drop('SCOPE', SCOPES), field('NAME', 'meta'), val('KEY'), val('VALUE')]),
        stmt('map_delete', 'delete key %3 of map %1 %2', [drop('SCOPE', SCOPES), field('NAME', 'meta'), val('KEY')]),

        // ============ literals ============
        expr('lit_text', '%1', [{ type: 'field_input', name: 'V', text: 'hello' }], C.text),
        expr('lit_num', '%1', [{ type: 'field_number', name: 'V', value: 0 }], C.math),
        expr('lit_bool', '%1', [drop('V', [['true', 'TRUE'], ['false', 'FALSE']])], C.logic),
        expr('lit_null', 'null', [], C.expr),

        // ============ context / payload ============
        expr('ex_payload', 'payload %1', [field('KEY', 'x')]),
        expr('ex_ctx', '%1', [drop('PROP', [
            ['player id', 'ctx.player_id'], ['player name', 'ctx.player_name'],
            ['player is host', 'ctx.player_is_host'], ['player seat', 'ctx.player_seat'],
            ['room id', 'ctx.room_id'], ['room name', 'ctx.room_name'],
            ['room status', 'ctx.room_status'], ['now (unix)', 'ctx.now'],
            ['action name', 'ctx.action'], ['timer name', 'ctx.timer'], ['event', 'ctx.event'],
        ])]),
        expr('ex_room_players', 'room players', []),
        expr('ex_room_player_count', 'room player count', []),

        // ============ math ============
        expr('math_bin', '%1 %2 %3', [val('A'), drop('OP', [
            ['+', 'add'], ['−', 'sub'], ['×', 'mul'], ['÷', 'div'], ['mod', 'mod'],
            ['min', 'min'], ['max', 'max'], ['pow', 'pow'],
        ]), val('B')], C.math),
        expr('math_un', '%1 %2', [drop('OP', [
            ['abs', 'abs'], ['floor', 'floor'], ['ceil', 'ceil'],
            ['round', 'round'], ['sqrt', 'sqrt'],
        ]), val('V')], C.math),
        expr('math_clamp', 'clamp %1 to %2 … %3', [val('V'), val('MIN'), val('MAX')], C.math),
        expr('math_rand_int', 'random int %1 … %2', [val('MIN'), val('MAX')], C.math),
        expr('math_rand_float', 'random float 0…1', [], C.math),
        expr('math_rand_pick', 'random item of %1', [val('LIST')], C.math),

        // ============ logic ============
        expr('cmp', '%1 %2 %3', [val('A'), drop('OP', [
            ['=', 'eq'], ['≠', 'ne'], ['<', 'lt'], ['≤', 'le'], ['>', 'gt'], ['≥', 'ge'],
        ]), val('B')], C.logic),
        expr('logic_bin', '%1 %2 %3', [val('A'), drop('OP', [
            ['and', 'and'], ['or', 'or'], ['xor', 'xor'],
        ]), val('B')], C.logic),
        expr('logic_not', 'not %1', [val('V')], C.logic),
        expr('logic_choose', 'if %1 then %2 else %3', [val('COND'), val('THEN'), val('ELSE')], C.logic),

        // ============ text ============
        expr('text_concat', 'join %1', [val('LIST')], C.text),
        expr('text_length', 'length of %1', [val('V')], C.text),
        expr('text_contains', '%1 contains %2', [val('HAYSTACK'), val('NEEDLE')], C.text),
        expr('text_case', '%1 %2', [drop('OP', [['upper', 'upper'], ['lower', 'lower']]), val('V')], C.text),
        expr('text_substring', 'substring %1 from %2 len %3', [val('V'), val('START'), val('LENGTH')], C.text),
        expr('text_format', 'format %1 with %2', [val('TEMPLATE'), val('ARGS')], C.text),

        // ============ lists ============
        {
            type: 'list_lit', message0: 'list %1', args0: [stmts('ITEMS')],
            output: null, colour: C.list,
        },
        {
            type: 'list_item', message0: 'item %1', args0: [val('V')],
            previousStatement: null, nextStatement: null, colour: C.list,
        },
        expr('list_get', 'item %2 of %1', [val('LIST'), val('INDEX')], C.list),
        expr('list_length', 'length of %1', [val('LIST')], C.list),
        expr('list_contains', '%1 contains %2', [val('LIST'), val('VALUE')], C.list),
        expr('list_index_of', 'index of %2 in %1', [val('LIST'), val('VALUE')], C.list),
        expr('list_shuffle', 'shuffle %1', [val('LIST')], C.list),
        expr('list_slice', 'slice %1 from %2 len %3', [val('LIST'), val('START'), val('LENGTH')], C.list),
        expr('list_join', 'join %1 with %2', [val('LIST'), val('SEP')], C.list),

        // ============ maps ============
        {
            type: 'map_lit', message0: 'map %1', args0: [stmts('ENTRIES')],
            output: null, colour: C.map,
        },
        {
            type: 'map_entry', message0: '%1 : %2', args0: [field('K', 'key'), val('V')],
            previousStatement: null, nextStatement: null, colour: C.map,
        },
        expr('map_get', 'key %2 of %1', [val('MAP'), val('KEY')], C.map),
        expr('map_has', '%1 has key %2', [val('MAP'), val('KEY')], C.map),
        expr('map_keys', 'keys of %1', [val('MAP')], C.map),
        expr('map_values', 'values of %1', [val('MAP')], C.map),

        // ============ conversion / inspection ============
        expr('conv', 'to %1 %2', [drop('OP', [
            ['number', 'to_number'], ['string', 'to_string'], ['bool', 'to_bool'],
        ]), val('V')]),
        expr('is_type', '%1 is %2', [val('V'), drop('OP', [
            ['null', 'is_null'], ['a list', 'is_list'], ['a map', 'is_map'],
            ['a number', 'is_number'], ['a string', 'is_string'], ['a bool', 'is_bool'],
        ])]),
        expr('json_encode', 'JSON encode %1', [val('V')]),
        expr('json_decode', 'JSON decode %1', [val('V')]),
    ];

    // Toolbox shown in the editor — categories mirror the ops doc.
    const TOOLBOX = {
        kind: 'categoryToolbox',
        contents: [
            cat('Events', C.event, ['ev_player_action', 'ev_message', 'ev_timer',
                'ev_player_join', 'ev_player_leave', 'ev_room_created', 'ev_match_start', 'ev_match_end']),
            cat('Variables', C.var, ['set_var', 'change_var', 'get_var']),
            cat('Control', C.control, ['ctl_if', 'ctl_if_else', 'ctl_repeat', 'ctl_while',
                'ctl_repeat_until', 'ctl_foreach', 'ctl_foreach_player', 'ctl_break']),
            cat('Functions', C.fn, ['fn_def', 'call_fn', 'call_fn_ret', 'return_stmt']),
            cat('Actions', C.action, ['act_respond', 'act_reject', 'act_log']),
            cat('Messages', C.comm, ['send_player', 'send_room', 'send_others']),
            cat('Timers', C.timer, ['tm_schedule', 'tm_cancel']),
            cat('Anti-cheat', '0', ['ac_require', 'ac_consume', 'ac_ratelimit',
                'ac_verify_move', 'ac_turn_assert', 'ac_turn_set', 'ac_turn_next',
                'ac_kick', 'ac_match_start', 'ac_match_end', 'ac_broadcast_log',
                'ex_turn_current', 'ex_room_member']),
            cat('Lists', C.list, ['list_lit', 'list_item', 'list_add', 'list_insert_at', 'list_set_at',
                'list_remove_at', 'list_clear', 'list_get', 'list_length', 'list_contains',
                'list_index_of', 'list_shuffle', 'list_slice', 'list_join']),
            cat('Maps', C.map, ['map_lit', 'map_entry', 'map_set', 'map_delete',
                'map_get', 'map_has', 'map_keys', 'map_values']),
            cat('Values', C.expr, ['lit_text', 'lit_num', 'lit_bool', 'lit_null',
                'ex_payload', 'ex_ctx', 'ex_room_players', 'ex_room_player_count']),
            cat('Math', C.math, ['math_bin', 'math_un', 'math_clamp',
                'math_rand_int', 'math_rand_float', 'math_rand_pick']),
            cat('Logic', C.logic, ['cmp', 'logic_bin', 'logic_not', 'logic_choose']),
            cat('Text', C.text, ['text_concat', 'text_length', 'text_contains',
                'text_case', 'text_substring', 'text_format']),
            cat('Convert', C.expr, ['conv', 'is_type', 'json_encode', 'json_decode']),
        ],
    };

    function cat(name, colour, types) {
        return {
            kind: 'category', name, colour: String(colour),
            contents: types.map((t) => ({ kind: 'block', type: t })),
        };
    }

    // ====================== AST generator ======================
    // Walk the workspace top-level: hat blocks → triggers, fn_def →
    // functions. Everything else is ignored (floating blocks are drafts).

    const EVENT_OPS = {
        ev_player_action: 'on_player_action', ev_message: 'on_message',
        ev_timer: 'on_timer', ev_player_join: 'on_player_join',
        ev_player_leave: 'on_player_leave', ev_room_created: 'on_room_created',
        ev_match_start: 'on_match_start', ev_match_end: 'on_match_end',
    };
    const EVENT_KEY_FIELD = {
        ev_player_action: 'ACTION', ev_message: 'MESSAGE', ev_timer: 'TIMER',
    };
    const EVENT_KEY_AST = {
        ev_player_action: 'action', ev_message: 'message', ev_timer: 'timer',
    };

    const scopeField = (b) => String(b.getFieldValue('SCOPE') || 'local').toLowerCase();
    const nameField = (b, n) => String(b.getFieldValue(n || 'NAME') || '');

    // input → expression node; absent input → null literal
    function inExpr(b, name) {
        const t = b.getInputTargetBlock(name);
        return t ? exprNode(t) : null;
    }
    function inStack(b, name) {
        const t = b.getInputTargetBlock(name);
        return t ? stackNodes(t) : [];
    }

    function stackNodes(top) {
        const out = [];
        for (let b = top; b; b = b.getNextBlock()) {
            const n = stmtNode(b);
            if (n) { out.push(n); }
        }
        return out;
    }

    function stmtNode(b) {
        const t = b.type;
        switch (t) {
            case 'set_var': return { op: 'set.' + scopeField(b), name: nameField(b), value: inExpr(b, 'VALUE') };
            case 'change_var': return { op: 'change.' + scopeField(b), name: nameField(b), delta: inExpr(b, 'DELTA') };
            case 'ctl_if': return { op: 'if', cond: inExpr(b, 'COND'), then: inStack(b, 'THEN') };
            case 'ctl_if_else': return { op: 'if', cond: inExpr(b, 'COND'), then: inStack(b, 'THEN'), else: inStack(b, 'ELSE') };
            case 'ctl_repeat': return { op: 'repeat', times: inExpr(b, 'TIMES'), body: inStack(b, 'BODY') };
            case 'ctl_while': return { op: 'while', cond: inExpr(b, 'COND'), body: inStack(b, 'BODY') };
            case 'ctl_repeat_until': return { op: 'repeat_until', cond: inExpr(b, 'COND'), body: inStack(b, 'BODY') };
            case 'ctl_foreach': return { op: 'foreach', list: inExpr(b, 'LIST'), as: nameField(b, 'AS'), body: inStack(b, 'BODY') };
            case 'ctl_foreach_player': return { op: 'foreach_player', as: nameField(b, 'AS'), body: inStack(b, 'BODY') };
            case 'ctl_break': return { op: 'break' };
            case 'call_fn': return { op: 'call', name: nameField(b), args: argList(inExpr(b, 'ARGS')) };
            case 'return_stmt': return { op: 'return', value: inExpr(b, 'VALUE') };
            case 'act_respond': return { op: 'respond', value: inExpr(b, 'VALUE') };
            case 'act_reject': return { op: 'reject', reason: inExpr(b, 'REASON') };
            case 'act_log': return { op: 'log', message: inExpr(b, 'MESSAGE') };
            case 'send_player': return { op: 'send.player', player: inExpr(b, 'PLAYER'), type: strField(b, 'TYPE'), data: inExpr(b, 'DATA') };
            case 'send_room': return { op: 'send.room', type: strField(b, 'TYPE'), data: inExpr(b, 'DATA') };
            case 'send_others': return { op: 'send.others', type: strField(b, 'TYPE'), data: inExpr(b, 'DATA') };
            case 'tm_schedule': return { op: 'schedule', timer: strField(b, 'TIMER'), delay: inExpr(b, 'DELAY'), payload: inExpr(b, 'PAYLOAD') };
            case 'tm_cancel': return { op: 'cancel_timer', timer: strField(b, 'TIMER') };
            case 'ac_require': return { op: 'require', cond: inExpr(b, 'COND'), reason: inExpr(b, 'REASON') };
            case 'ac_consume': return { op: 'consume', scope: scopeField(b), name: nameField(b), amount: inExpr(b, 'AMOUNT') };
            case 'ac_ratelimit': return { op: 'ratelimit', limit: inExpr(b, 'LIMIT'), per: inExpr(b, 'PER'), key: inExpr(b, 'KEY') };
            case 'ac_verify_move': return { op: 'verify.move', from: inExpr(b, 'FROM'), to: inExpr(b, 'TO'), max: inExpr(b, 'MAX') };
            case 'ac_turn_assert': return { op: 'turn.assert' };
            case 'ac_turn_set': return { op: 'turn.set', player: inExpr(b, 'PLAYER') };
            case 'ac_turn_next': return { op: 'turn.next' };
            case 'ac_kick': return { op: 'player.kick', player: inExpr(b, 'PLAYER'), reason: inExpr(b, 'REASON') };
            case 'ac_match_start': return { op: 'match.start', data: inExpr(b, 'DATA') };
            case 'ac_match_end': return { op: 'match.end', result: inExpr(b, 'RESULT') };
            case 'ac_broadcast_log': return { op: 'broadcast.log', message: inExpr(b, 'MESSAGE') };
            case 'list_add': return { op: 'list.add', scope: scopeField(b), name: nameField(b), value: inExpr(b, 'VALUE') };
            case 'list_insert_at': return { op: 'list.insert_at', scope: scopeField(b), name: nameField(b), index: inExpr(b, 'INDEX'), value: inExpr(b, 'VALUE') };
            case 'list_set_at': return { op: 'list.set_at', scope: scopeField(b), name: nameField(b), index: inExpr(b, 'INDEX'), value: inExpr(b, 'VALUE') };
            case 'list_remove_at': return { op: 'list.remove_at', scope: scopeField(b), name: nameField(b), index: inExpr(b, 'INDEX') };
            case 'list_clear': return { op: 'list.clear', scope: scopeField(b), name: nameField(b) };
            case 'map_set': return { op: 'map.set', scope: scopeField(b), name: nameField(b), key: inExpr(b, 'KEY'), value: inExpr(b, 'VALUE') };
            case 'map_delete': return { op: 'map.delete', scope: scopeField(b), name: nameField(b), key: inExpr(b, 'KEY') };
            default:
                // expression block floating in statement position — emit as
                // expression-statement (executor evaluates + discards)
                if (EXPR_SERIALIZERS[t]) { return exprNode(b); }
                return null;
        }
    }

    function strField(b, name) {
        return { op: 'lit', value: nameField(b, name) };
    }

    // call args: accept either a list_lit block or a raw value
    function argList(node) {
        if (node && node.op === 'list_lit') { return node.items; }
        return node === null || node === undefined ? [] : [node];
    }

    function exprNode(b) {
        const t = b.type;
        const f = EXPR_SERIALIZERS[t];
        if (!f) { return null; }
        return f(b);
    }

    const EXPR_SERIALIZERS = {
        lit_text: (b) => String(b.getFieldValue('V') ?? ''),
        lit_num: (b) => Number(b.getFieldValue('V') ?? 0),
        lit_bool: (b) => b.getFieldValue('V') === 'TRUE',
        lit_null: () => null,
        get_var: (b) => ({ op: 'var.' + scopeField(b), name: nameField(b) }),
        ex_payload: (b) => ({ op: 'payload', key: nameField(b, 'KEY') }),
        ex_ctx: (b) => ({ op: String(b.getFieldValue('PROP')) }),
        ex_room_players: () => ({ op: 'room.players' }),
        ex_room_player_count: () => ({ op: 'room.player_count' }),
        ex_room_member: (b) => ({ op: 'room.member', id: inExpr(b, 'ID') }),
        ex_turn_current: () => ({ op: 'turn.current' }),
        math_bin: (b) => ({ op: String(b.getFieldValue('OP')), a: inExpr(b, 'A'), b: inExpr(b, 'B') }),
        math_un: (b) => ({ op: String(b.getFieldValue('OP')), v: inExpr(b, 'V') }),
        math_clamp: (b) => ({ op: 'clamp', v: inExpr(b, 'V'), min: inExpr(b, 'MIN'), max: inExpr(b, 'MAX') }),
        math_rand_int: (b) => ({ op: 'random_int', min: inExpr(b, 'MIN'), max: inExpr(b, 'MAX') }),
        math_rand_float: () => ({ op: 'random_float' }),
        math_rand_pick: (b) => ({ op: 'random_pick', list: inExpr(b, 'LIST') }),
        cmp: (b) => ({ op: String(b.getFieldValue('OP')), a: inExpr(b, 'A'), b: inExpr(b, 'B') }),
        logic_bin: (b) => ({ op: String(b.getFieldValue('OP')), a: inExpr(b, 'A'), b: inExpr(b, 'B') }),
        logic_not: (b) => ({ op: 'not', v: inExpr(b, 'V') }),
        logic_choose: (b) => ({ op: 'choose', cond: inExpr(b, 'COND'), then: inExpr(b, 'THEN'), else: inExpr(b, 'ELSE') }),
        text_concat: (b) => ({ op: 'concat', args: listItemsOrSingle(inExpr(b, 'LIST')) }),
        text_length: (b) => ({ op: 'text_length', v: inExpr(b, 'V') }),
        text_contains: (b) => ({ op: 'text_contains', haystack: inExpr(b, 'HAYSTACK'), needle: inExpr(b, 'NEEDLE') }),
        text_case: (b) => ({ op: String(b.getFieldValue('OP')), v: inExpr(b, 'V') }),
        text_substring: (b) => ({ op: 'substring', v: inExpr(b, 'V'), start: inExpr(b, 'START'), length: inExpr(b, 'LENGTH') }),
        text_format: (b) => ({ op: 'format', template: inExpr(b, 'TEMPLATE'), args: inExpr(b, 'ARGS') }),
        list_lit: (b) => ({ op: 'list_lit', items: listItemsOrSingle(null, b) }),
        list_get: (b) => ({ op: 'list_get', list: inExpr(b, 'LIST'), index: inExpr(b, 'INDEX') }),
        list_length: (b) => ({ op: 'list_length', list: inExpr(b, 'LIST') }),
        list_contains: (b) => ({ op: 'list_contains', list: inExpr(b, 'LIST'), value: inExpr(b, 'VALUE') }),
        list_index_of: (b) => ({ op: 'list_index_of', list: inExpr(b, 'LIST'), value: inExpr(b, 'VALUE') }),
        list_shuffle: (b) => ({ op: 'list_shuffle', list: inExpr(b, 'LIST') }),
        list_slice: (b) => ({ op: 'list_slice', list: inExpr(b, 'LIST'), start: inExpr(b, 'START'), length: inExpr(b, 'LENGTH') }),
        list_join: (b) => ({ op: 'list_join', list: inExpr(b, 'LIST'), sep: inExpr(b, 'SEP') }),
        map_lit: (b) => ({ op: 'map_lit', entries: mapEntries(b) }),
        map_get: (b) => ({ op: 'map_get', map: inExpr(b, 'MAP'), key: inExpr(b, 'KEY') }),
        map_has: (b) => ({ op: 'map_has', map: inExpr(b, 'MAP'), key: inExpr(b, 'KEY') }),
        map_keys: (b) => ({ op: 'map_keys', map: inExpr(b, 'MAP') }),
        map_values: (b) => ({ op: 'map_values', map: inExpr(b, 'MAP') }),
        conv: (b) => ({ op: String(b.getFieldValue('OP')), v: inExpr(b, 'V') }),
        is_type: (b) => ({ op: String(b.getFieldValue('OP')), v: inExpr(b, 'V') }),
        json_encode: (b) => ({ op: 'json_encode', v: inExpr(b, 'V') }),
        json_decode: (b) => ({ op: 'json_decode', v: inExpr(b, 'V') }),
        call_fn_ret: (b) => ({ op: 'call', name: nameField(b), args: argList(inExpr(b, 'ARGS')) }),
    };

    // list_lit: ITEMS is a vertical stack of list_item blocks.
    function listItemsOrSingle(node, listBlock) {
        if (listBlock) {
            const items = [];
            for (let it = listBlock.getInputTargetBlock('ITEMS'); it; it = it.getNextBlock()) {
                if (it.type === 'list_item') { items.push(inExpr(it, 'V')); }
            }
            return items;
        }
        if (node && node.op === 'list_lit') { return node.items; }
        return node == null ? [] : [node];
    }

    function mapEntries(b) {
        const entries = [];
        for (let it = b.getInputTargetBlock('ENTRIES'); it; it = it.getNextBlock()) {
            if (it.type === 'map_entry') {
                entries.push({ key: nameField(it, 'K'), value: inExpr(it, 'V') });
            }
        }
        return entries;
    }

    // Public: build the canonical AST from the workspace + defaults object.
    function generateAst(workspace, variables) {
        const ast = { version: 1, variables: variables || {}, functions: [], triggers: [] };
        for (const top of workspace.getTopBlocks(false)) {
            if (EVENT_OPS[top.type]) {
                // hat blocks carry their body as the next-statement chain
                const trig = {
                    event: EVENT_OPS[top.type],
                    body: stackNodes(top.getNextBlock()),
                };
                const keyField = EVENT_KEY_FIELD[top.type];
                if (keyField) { trig[EVENT_KEY_AST[top.type]] = nameField(top, keyField); }
                ast.triggers.push(trig);
            } else if (top.type === 'fn_def') {
                ast.functions.push({
                    name: nameField(top),
                    params: nameField(top, 'PARAMS').split(',').map((s) => s.trim()).filter(Boolean),
                    body: inStack(top, 'BODY'),
                });
            }
        }
        return ast;
    }

    window.MLC = { BLOCKS, TOOLBOX, generateAst };
})();
