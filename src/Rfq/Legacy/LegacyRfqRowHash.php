<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

/**
 * One row's identity and content as both stores should agree on them, so a
 * MySQL row and its SQL Server copy hash the same: NULL stays NULL; integers
 * and floats by value (the two engines spell them differently); CHAR(n)
 * without the padding SQL Server adds; text byte for byte.
 *
 * The 20 Oct 2026 import report's per-table digests were computed this way,
 * which is what lets `--adopt` prove the platform rows are still that import.
 */
final class LegacyRfqRowHash
{
    /**
     * Column kinds from the SQL Server type names (sys.types).
     *
     * @param list<array{name: string, type: string}> $columns
     *
     * @return list<array{name: string, kind: string}>
     */
    public static function kinds(array $columns): array
    {
        return array_map(static fn (array $c): array => [
            'name' => $c['name'],
            'kind' => match ($c['type']) {
                'int', 'tinyint', 'smallint', 'bigint' => 'int',
                'float', 'real' => 'float',
                'char', 'nchar' => 'char',
                default => 'text',
            },
        ], $columns);
    }

    public static function canonical(mixed $value, string $kind): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = self::text($value);

        return match ($kind) {
            'int' => (string) (int) $text,
            'float' => sprintf('%.17g', (float) $text),
            'char' => rtrim($text, ' '),
            default => $text,
        };
    }

    /** A column value as text; both drivers hand over scalars or NULL. */
    public static function text(mixed $value): string
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \UnexpectedValueException('A column value that is not a scalar: ' . get_debug_type($value));
        }

        return (string) $value;
    }

    /**
     * The primary key as text, `\x1f` between columns.
     *
     * @param array<string, mixed> $row
     * @param list<string> $pk
     */
    public static function key(array $row, array $pk): string
    {
        return implode("\x1f", array_map(static fn (string $c): string => rtrim(self::text($row[$c] ?? null), ' '), $pk));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{name: string, kind: string}> $columns
     */
    public static function row(array $row, array $columns): string
    {
        return hash('sha256', json_encode(
            array_map(static fn (array $c): ?string => self::canonical($row[$c['name']], $c['kind']), $columns),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    /**
     * A table's digest as the import report has it: the row hashes in key
     * order (byte order), one per line.
     *
     * @param array<string, string> $hashes key => row hash
     */
    public static function digest(array $hashes): string
    {
        ksort($hashes, SORT_STRING);

        return hash('sha256', implode("\n", $hashes));
    }
}
