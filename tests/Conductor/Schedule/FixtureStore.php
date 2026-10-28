<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Conductor\Schedule;

use PDO;
use RuntimeException;

/**
 * Builds a throwaway conductor schedule store for tests: it runs the engine's own schema.sql and inserts the rows of
 * {@see FixtureData}. A change to the schema that breaks these inserts fails a PHP test.
 */
final class FixtureStore
{
    /** @var list<string> */
    private static array $created = [];

    public static function schemaPath(): string
    {
        return dirname(__DIR__, 3) . '/core/python/conductor_schedule/schema.sql';
    }

    /** A complete store. */
    public static function create(): string
    {
        $path = self::tempPath();
        self::fill(self::open($path));

        return $path;
    }

    /** A store with the schema and every table filled but no cs_run row. */
    public static function createWithoutRun(): string
    {
        $path = self::tempPath();
        $pdo = self::open($path);
        self::fill($pdo);
        $pdo->exec('DELETE FROM cs_run');

        return $path;
    }

    /** A store written by some other schema version. */
    public static function createWithVersion(int $version): string
    {
        $path = self::tempPath();
        $pdo = self::open($path);
        self::fill($pdo);
        $pdo->exec('PRAGMA user_version = ' . $version);

        return $path;
    }

    /** A path in the temp folder where nothing exists. */
    public static function missingPath(): string
    {
        return sys_get_temp_dir() . '/conductor-schedule-missing-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    /** A file that exists but is not a SQLite database. */
    public static function createGarbage(): string
    {
        $path = self::tempPath();
        file_put_contents($path, str_repeat('not a database ', 100));

        return $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        self::$created = [];
    }

    private static function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/conductor-schedule-fixture-' . bin2hex(random_bytes(6)) . '.sqlite';
        self::$created[] = $path;

        return $path;
    }

    private static function open(string $path): PDO
    {
        $schema = file_get_contents(self::schemaPath());
        if ($schema === false) {
            throw new RuntimeException('core/python/conductor_schedule/schema.sql is missing.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($schema);

        return $pdo;
    }

    private static function fill(PDO $pdo): void
    {
        $pdo->beginTransaction();
        self::insert($pdo, 'cs_run', ['runId' => 1] + FixtureData::run());
        foreach (['cs_source' => FixtureData::sources(), 'cs_setting' => FixtureData::settings(), 'cs_r86' => FixtureData::r86(),
            'cs_demand' => FixtureData::demand(), 'cs_restock' => FixtureData::restock(), 'cs_job' => FixtureData::jobs(),
            'cs_machine' => FixtureData::machines(), 'cs_op' => FixtureData::ops(), 'cs_batch' => FixtureData::batches(),
            'cs_pass' => FixtureData::passes(), 'cs_draw' => FixtureData::draws(), 'cs_stock' => FixtureData::stock(),
            'cs_capacity' => FixtureData::capacity(), 'cs_load' => FixtureData::load(), 'cs_issue' => FixtureData::issues()] as $table => $rows) {
            foreach ($rows as $r) {
                self::insert($pdo, $table, $r);
            }
        }
        $pdo->commit();
    }

    /**
     * Inserts a typed camelCase record as its snake_case columns (booleans as 0/1, lists and objects as JSON text).
     *
     * @param array<string, mixed> $record
     */
    private static function insert(PDO $pdo, string $table, array $record): void
    {
        $columns = [];
        $values = [];
        foreach ($record as $key => $value) {
            $columns[] = match ($key) {
                'runs52w' => 'runs_52w',
                'issueWeekly52wM' => 'issue_weekly_52w_m',
                default => strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $key)),
            };
            if ($table === 'cs_setting' && $key === 'value') {
                $values[] = json_encode($value, JSON_THROW_ON_ERROR);
            } elseif (is_array($value)) {
                $values[] = json_encode($value, JSON_THROW_ON_ERROR);
            } elseif (is_bool($value)) {
                $values[] = (int) $value;
            } elseif ($value === null || is_scalar($value)) {
                $values[] = $value;
            } else {
                throw new RuntimeException('Unsupported fixture value for ' . $table . '.' . $key);
            }
        }
        $marks = implode(', ', array_fill(0, count($columns), '?'));
        $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $marks . ')')->execute($values);
    }
}
