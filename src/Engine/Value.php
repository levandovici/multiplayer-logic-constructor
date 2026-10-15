<?php

namespace Mlc\Engine;

// Value coercion for the block language. Values are JSON types: null, bool,
// int/float, string, list (sequential array), map (assoc array). Coercion
// rules are deliberately forgiving and total — blocks never crash on
// type mismatch unless a value is structurally unusable.
final class Value
{
    public static function num(mixed $v): int|float
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_string($v) && is_numeric($v)) {
            return $v + 0;
        }
        throw new EngineError('expected a number, got ' . self::typeName($v));
    }

    public static function int(mixed $v): int
    {
        return (int)self::num($v);
    }

    public static function bool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        if (is_string($v)) {
            return $v !== '' && $v !== '0';
        }
        if (is_array($v)) {
            return $v !== [];
        }
        return false;
    }

    public static function str(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return '';
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public static function listOf(mixed $v): array
    {
        if (is_array($v) && self::isList($v)) {
            return $v;
        }
        throw new EngineError('expected a list, got ' . self::typeName($v));
    }

    public static function mapOf(mixed $v): array
    {
        if (is_array($v) && !self::isList($v)) {
            return $v;
        }
        if ($v === null) {
            return [];
        }
        throw new EngineError('expected a map, got ' . self::typeName($v));
    }

    /** PHP array with sequential 0..n-1 integer keys = list. */
    public static function isList(array $v): bool
    {
        return $v === [] || array_keys($v) === range(0, count($v) - 1);
    }

    public static function typeName(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => 'bool',
            is_int($v), is_float($v) => 'number',
            is_string($v) => 'string',
            is_array($v) && self::isList($v) => 'list',
            is_array($v) => 'map',
            default => get_debug_type($v),
        };
    }

    /** Deep-compare for `eq` — strict on scalars, structural on arrays. */
    public static function equals(mixed $a, mixed $b): bool
    {
        return $a === $b || (is_array($a) && is_array($b) && $a == $b);
    }
}
