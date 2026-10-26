<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * Reading Odoo JSON-2 row values. Odoo answers `false` for an empty field of any type, a many2one as `[id, "Name"]`,
 * an x2many as a list of ids, a date as `yyyy-MM-dd` and a datetime as `yyyy-MM-dd HH:mm:ss` (UTC).
 */
final class Ap2OdooValues
{
    public static function id(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /** A many2one's id: `[7, "Name"]` (or a bare id) → 7; `false` → null. */
    public static function many2oneId(mixed $value): ?int
    {
        if (is_array($value) && array_is_list($value)) {
            return self::id($value[0] ?? null);
        }

        return self::id($value);
    }

    /** A many2one's display name: `[7, "Name"]` → "Name"; `false` → ''. */
    public static function many2oneName(mixed $value): string
    {
        return is_array($value) && is_string($value[1] ?? null) ? $value[1] : '';
    }

    /** @return list<int> the ids of an x2many */
    public static function ids(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $id) {
            if (is_int($id) && $id > 0) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** Text, '' for `false`, trimmed. */
    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    public static function number(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /** `yyyy-MM-dd` from a date or a datetime, or null. */
    public static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1 ? $m[1] : null;
    }

    public static function bool(mixed $value): bool
    {
        return $value === true;
    }
}
