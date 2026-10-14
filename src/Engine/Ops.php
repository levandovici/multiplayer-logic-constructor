<?php

namespace Mlc\Engine;

// The complete opcode whitelist — single source of truth for Program
// validation and the editor's block registry (api/blocks.php maps these to
// Blockly blocks). An op absent from both lists is unexecutable by
// construction.
final class Ops
{
    /** Statement ops — run for effect, return nothing. */
    public const STATEMENTS = [
        // variable writes: scope in {local, player, room, game}
        'set.local', 'set.player', 'set.room', 'set.game',
        'change.local', 'change.player', 'change.room', 'change.game',
        // named-list mutation: {scope, name, ...} (+optional player)
        'list.add', 'list.insert_at', 'list.set_at', 'list.remove_at', 'list.clear',
        // named-map mutation: {scope, name, key, ...}
        'map.set', 'map.delete',
        // control flow
        'if', 'repeat', 'while', 'repeat_until', 'foreach', 'foreach_player', 'break',
        // functions
        'call', 'return',
        // action outcome
        'respond', 'reject',
        // communication
        'send.player', 'send.room', 'send.others', 'broadcast.log',
        // timers
        'schedule', 'cancel_timer',
        // audit
        'log',
        // anti-cheat primitives
        'require', 'consume', 'ratelimit', 'verify.move',
        'turn.assert', 'turn.set', 'turn.next',
        'player.kick', 'match.start', 'match.end',
    ];

    /** Expression ops — evaluate to a value. */
    public const EXPRESSIONS = [
        'lit',
        'var.local', 'var.player', 'var.room', 'var.game',
        'payload',
        'ctx.player_id', 'ctx.player_name', 'ctx.player_is_host', 'ctx.player_seat',
        'ctx.room_id', 'ctx.room_name', 'ctx.room_status', 'ctx.now',
        'ctx.action', 'ctx.event', 'ctx.timer',
        'room.players', 'room.player_count', 'room.member',
        'turn.current',
        // math
        'add', 'sub', 'mul', 'div', 'mod',
        'abs', 'min', 'max', 'clamp', 'floor', 'ceil', 'round', 'pow', 'sqrt',
        'random_int', 'random_float', 'random_pick',
        // comparison & logic
        'eq', 'ne', 'lt', 'le', 'gt', 'ge',
        'and', 'or', 'not', 'xor', 'choose',
        // text
        'concat', 'text_length', 'text_contains', 'upper', 'lower',
        'substring', 'format',
        // list expressions
        'list_lit', 'list_get', 'list_length', 'list_contains', 'list_index_of',
        'list_shuffle', 'list_slice', 'list_join',
        // map expressions
        'map_lit', 'map_get', 'map_has', 'map_keys', 'map_values',
        // conversion & inspection
        'to_number', 'to_string', 'to_bool',
        'is_null', 'is_list', 'is_map', 'is_number', 'is_string', 'is_bool',
        'json_encode', 'json_decode',
        // function call as expression (returns the function's value)
        'call',
    ];

    public const EVENTS = [
        'on_player_action', 'on_message', 'on_player_join', 'on_player_leave',
        'on_room_created', 'on_match_start', 'on_match_end', 'on_timer',
    ];

    /** Events keyed by a trigger field (action name / timer name / message). */
    public const KEYED_EVENTS = [
        'on_player_action' => 'action',
        'on_timer' => 'timer',
        'on_message' => 'message',
    ];

    public static function isStatement(string $op): bool
    {
        return in_array($op, self::STATEMENTS, true);
    }

    public static function isExpression(string $op): bool
    {
        return in_array($op, self::EXPRESSIONS, true);
    }

    public static function isKnown(string $op): bool
    {
        return self::isStatement($op) || self::isExpression($op);
    }

    public static function isEvent(string $event): bool
    {
        return in_array($event, self::EVENTS, true);
    }

    public static function eventKeyField(string $event): ?string
    {
        return self::KEYED_EVENTS[$event] ?? null;
    }
}
