<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Conductor\Process;

use PDO;
use RuntimeException;

/**
 * Builds a throwaway conductor process store for tests: it runs the engine's own schema.sql and inserts the rows of
 * {@see FixtureData}. A change to the schema that breaks these inserts fails a PHP test.
 */
final class FixtureStore
{
    /** @var list<string> */
    private static array $created = [];

    public static function schemaPath(): string
    {
        return dirname(__DIR__, 3) . '/core/python/conductor_process/schema.sql';
    }

    /** A complete store. */
    public static function create(): string
    {
        $path = self::tempPath();
        self::fill(self::open($path));

        return $path;
    }

    /** A store with the schema and every table filled but no cd_run row. */
    public static function createWithoutRun(): string
    {
        $path = self::tempPath();
        $pdo = self::open($path);
        self::fill($pdo);
        $pdo->exec('DELETE FROM cd_run');

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
        return sys_get_temp_dir() . '/conductor-process-missing-' . bin2hex(random_bytes(6)) . '.sqlite';
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
        $path = sys_get_temp_dir() . '/conductor-process-fixture-' . bin2hex(random_bytes(6)) . '.sqlite';
        self::$created[] = $path;

        return $path;
    }

    private static function open(string $path): PDO
    {
        $schema = file_get_contents(self::schemaPath());
        if ($schema === false) {
            throw new RuntimeException('core/python/conductor_process/schema.sql is missing.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($schema);

        return $pdo;
    }

    private static function fill(PDO $pdo): void
    {
        $pdo->beginTransaction();
        self::insert($pdo, 'cd_run', ['runId' => 1] + FixtureData::run());
        foreach (FixtureData::machines() as $r) {
            self::insert($pdo, 'cd_machine', $r);
        }
        foreach (FixtureData::machineOperations() as $r) {
            self::insert($pdo, 'cd_machine_operation', $r);
        }
        foreach (FixtureData::items() as $r) {
            self::insert($pdo, 'cd_item', $r);
        }
        foreach (FixtureData::operations() as $r) {
            self::insert($pdo, 'cd_operation', $r);
        }
        foreach (FixtureData::inputs() as $r) {
            self::insert($pdo, 'cd_input', $r);
        }
        foreach (FixtureData::altMachines() as $r) {
            self::insert($pdo, 'cd_alt_machine', $r);
        }
        foreach (FixtureData::materials() as $r) {
            self::insert($pdo, 'cd_material', $r);
        }
        foreach (FixtureData::needs() as $r) {
            self::insert($pdo, 'cd_need', $r);
        }
        $pdo->commit();
    }

    /**
     * Inserts a typed camelCase record as its snake_case columns (booleans as 0/1).
     *
     * @param array<string, string|int|float|bool|null> $record
     */
    private static function insert(PDO $pdo, string $table, array $record): void
    {
        $columns = [];
        $values = [];
        foreach ($record as $key => $value) {
            $columns[] = $key === 'orders52w' ? 'orders_52w' : strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $key));
            $values[] = is_bool($value) ? (int) $value : $value;
        }
        $marks = implode(', ', array_fill(0, count($columns), '?'));
        $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $marks . ')')->execute($values);
    }
}
