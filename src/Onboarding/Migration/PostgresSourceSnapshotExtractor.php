<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use PDO;
use RuntimeException;
use TriCab\Core\Onboarding\Migration\OnboardingMigrationRefused;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshotSchema;

/**
 * Reads the source's Postgres into a {@see SourceSnapshot} (Phase 7.1).
 *
 * ## Read-only, three ways
 *
 *  1. Every statement is built here from {@see SourceSnapshotSchema::TABLES} —
 *     `SELECT <allow-listed columns> FROM <allow-listed table> ORDER BY id` —
 *     and checked again before it is sent: one statement, starting with SELECT,
 *     no semicolon.
 *  2. All of them run inside `BEGIN TRANSACTION READ ONLY`, so Postgres itself
 *     refuses a write, and the transaction is rolled back, never committed.
 *  3. The source database is never named in configuration the platform reads
 *     at runtime: the URL comes from `ONBOARDING_MIGRATION_SOURCE_DATABASE_URL`
 *     for the one CLI run that needs it.
 *
 * Values are read in a portable form — timestamps as UTC ISO-8601, dates as
 * `YYYY-MM-DD`, JSONB and UUIDs as text — so the snapshot is independent of the
 * session's time zone and of the driver's type mapping.
 *
 * The real source has **not** been read (work order §7: synthetic data only).
 * It is exercised against a local Postgres loaded with the source's own
 * `db/schema.sql` and synthetic rows.
 */
final readonly class PostgresSourceSnapshotExtractor
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Opens a connection from a `postgres://user:pass@host:port/db?sslmode=…` URL.
     */
    public static function connect(string $databaseUrl): self
    {
        return new self(self::openPdo($databaseUrl));
    }

    public static function openPdo(string $databaseUrl): PDO
    {
        $parts = parse_url($databaseUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true) || ($parts['host'] ?? '') === '') {
            throw new RuntimeException('ONBOARDING_MIGRATION_SOURCE_DATABASE_URL is not a postgres:// URL.');
        }
        parse_str($parts['query'] ?? '', $query);
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s%s',
            $parts['host'],
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/'),
            is_string($query['sslmode'] ?? null) ? ';sslmode=' . $query['sslmode'] : '',
        );

        return new PDO($dsn, isset($parts['user']) ? urldecode($parts['user']) : null, isset($parts['pass']) ? urldecode($parts['pass']) : null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * @param list<string> $adminEmails the source's ADMIN_EMAILS setting
     *
     * @return array<string, mixed> a snapshot document; {@see SourceSnapshot::fromArray()} validates it
     */
    public function extract(bool $synthetic, array $adminEmails, \DateTimeImmutable $now): array
    {
        $this->pdo->exec('BEGIN TRANSACTION READ ONLY');
        try {
            $tables = [];
            foreach (SourceSnapshotSchema::TABLES as $table => $columns) {
                $tables[$table] = array_map(
                    static fn (array $row): array => self::decode($row, $columns),
                    $this->select(self::selectFor($table, $columns)),
                );
            }
        } finally {
            $this->pdo->exec('ROLLBACK');
        }

        return [
            'format' => SourceSnapshotSchema::FORMAT,
            'sourceSystem' => SourceSnapshotSchema::SOURCE_SYSTEM,
            'synthetic' => $synthetic,
            'extractedAtUtc' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'adminEmails' => $adminEmails,
            'tables' => $tables,
        ];
    }

    /**
     * @param array<string, string> $columns
     */
    public static function selectFor(string $table, array $columns): string
    {
        if (!isset(SourceSnapshotSchema::TABLES[$table])) {
            throw OnboardingMigrationRefused::because('SOURCE_READ', $table . ' is not on the allow-list');
        }
        $list = [];
        foreach ($columns as $column => $type) {
            if (!isset(SourceSnapshotSchema::TABLES[$table][$column])) {
                throw OnboardingMigrationRefused::because('SOURCE_READ', $table . '.' . $column . ' is not on the allow-list');
            }
            $quoted = '"' . $column . '"';
            $list[] = match ($type) {
                SourceSnapshotSchema::TIMESTAMP => sprintf("to_char(%s AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS %s", $quoted, $quoted),
                SourceSnapshotSchema::DATE => sprintf("to_char(%s, 'YYYY-MM-DD') AS %s", $quoted, $quoted),
                SourceSnapshotSchema::JSON, SourceSnapshotSchema::UUID => sprintf('%s::text AS %s', $quoted, $quoted),
                default => $quoted,
            };
        }

        return sprintf('SELECT %s FROM "public"."%s" ORDER BY "id"', implode(', ', $list), $table);
    }

    /** @return list<array<string, mixed>> */
    private function select(string $sql): array
    {
        if (preg_match('/^SELECT\s/', $sql) !== 1 || str_contains($sql, ';')) {
            throw OnboardingMigrationRefused::because('SOURCE_READ', 'refused a statement that is not a single SELECT');
        }
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('The source query failed.');
        }

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $columns
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row, array $columns): array
    {
        foreach ($columns as $column => $type) {
            $value = $row[$column] ?? null;
            if ($value === null) {
                continue;
            }
            $row[$column] = match ($type) {
                SourceSnapshotSchema::JSON => json_decode(is_string($value) ? $value : 'null', true, 512, JSON_THROW_ON_ERROR),
                SourceSnapshotSchema::ID, SourceSnapshotSchema::INT => is_numeric($value) ? (int) $value : $value,
                SourceSnapshotSchema::BOOL => is_bool($value) ? $value : in_array($value, [1, '1', 't', 'true'], true),
                default => $value,
            };
        }

        return $row;
    }
}
