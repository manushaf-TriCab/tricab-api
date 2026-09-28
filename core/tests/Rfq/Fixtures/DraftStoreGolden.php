<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Fixtures;

use RuntimeException;
use TriCab\Core\Rfq\Storage\PythonJson;

/**
 * Typed access to `draft-store-golden.json`, the source store's own output
 * (`generate_draft_store_golden.py`). Read with {@see PythonJson}, because it
 * carries a NaN, as the live data does.
 *
 * A scenario's `state` is what the source's tables held afterwards:
 * `draft` (the `rfq_draft_quotes` row), `rows`, `edits` and `activity`.
 */
final class DraftStoreGolden
{
    /** @var array<string, mixed> */
    private static ?array $data = null;

    /** @return array<string, mixed> */
    private static function data(): array
    {
        if (self::$data === null) {
            $json = file_get_contents(__DIR__ . '/draft-store-golden.json');
            if ($json === false) {
                throw new RuntimeException('draft-store-golden.json is missing.');
            }
            self::$data = self::map(PythonJson::decode($json));
        }

        return self::$data;
    }

    public static function frozenNow(): string
    {
        return self::string(self::data()['frozen_now'] ?? null);
    }

    /** @return list<array{in_json: string, out: string}> */
    public static function editTextCases(): array
    {
        return array_map(static function (mixed $case): array {
            $case = self::map($case);

            return ['in_json' => self::string($case['in_json'] ?? null), 'out' => self::string($case['out'] ?? null)];
        }, self::list(self::data()['edit_text'] ?? null));
    }

    /** @return list<array{row: array<string, mixed>, values: array<string, string>, summary: string}> */
    public static function trackedCases(): array
    {
        return array_map(static function (mixed $case): array {
            $case = self::map($case);

            return [
                'row' => self::map($case['row'] ?? null),
                'values' => array_map(self::string(...), self::map($case['values'] ?? null)),
                'summary' => self::string($case['summary'] ?? null),
            ];
        }, self::list(self::data()['tracked'] ?? null));
    }

    /** @return list<array<string, mixed>> the create step's input lines */
    public static function createRows(): array
    {
        return array_map(static fn (array $case): array => $case['row'], self::trackedCases());
    }

    /** @return list<string> Python's `json.dumps` output for varied values */
    public static function jsonDumps(): array
    {
        return array_map(
            static fn (mixed $case): string => self::string(self::map($case)['text'] ?? null),
            self::list(self::data()['json_dumps'] ?? null),
        );
    }

    public static function jsonDumpsNan(): string
    {
        return self::string(self::data()['json_dumps_nan'] ?? null);
    }

    /** @return array<string, mixed> */
    public static function scenario(string $name): array
    {
        foreach (self::list(self::data()['scenarios'] ?? null) as $scenario) {
            $scenario = self::map($scenario);
            if (($scenario['name'] ?? null) === $name) {
                return $scenario;
            }
        }
        throw new RuntimeException("No golden scenario {$name}.");
    }

    /**
     * A scenario's tables, every value as text or null, columns sorted by
     * name: SQLite's column order is the source's history (`updated_at` came
     * by ALTER), and only the values must match.
     *
     * @return array{draft: array<string, ?string>, rows: list<array<string, ?string>>, edits: list<array<string, ?string>>, activity: list<array<string, ?string>>}
     */
    public static function tables(string $scenario, string $key = 'state'): array
    {
        $state = self::map(self::scenario($scenario)[$key] ?? null);

        return [
            'draft' => self::textRow($state['draft'] ?? null),
            'rows' => array_map(self::textRow(...), self::list($state['rows'] ?? null)),
            'edits' => array_map(self::textRow(...), self::list($state['edits'] ?? null)),
            'activity' => array_map(self::textRow(...), self::list($state['activity'] ?? null)),
        ];
    }

    public static function int(string $scenario, string $key): int
    {
        $value = self::scenario($scenario)[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException("Golden {$scenario}.{$key} is not an integer.");
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    public static function lines(string $scenario, string $key): array
    {
        return array_map(self::map(...), self::list(self::scenario($scenario)[$key] ?? null));
    }

    /** @return array<string, mixed> */
    public static function object(string $scenario, string $key): array
    {
        return self::map(self::scenario($scenario)[$key] ?? null);
    }

    /** @return array<string, ?string> */
    public static function textRow(mixed $row): array
    {
        $out = [];
        foreach (self::map($row) as $column => $value) {
            $out[$column] = match (true) {
                $value === null => null,
                is_scalar($value) => (string) $value,
                default => throw new RuntimeException("Golden column {$column} is not scalar."),
            };
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Golden value is not an object.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Golden value is not a list.');
        }

        return $value;
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Golden value is not a string.');
        }

        return $value;
    }
}
