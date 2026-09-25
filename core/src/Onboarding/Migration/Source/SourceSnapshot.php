<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Source;

use TriCab\Core\Onboarding\Migration\OnboardingMigrationRefused;

/**
 * One read of the source, frozen (Phase 7.1): the staging copy every later
 * step — preview, execution, verification — works from, so the source is read
 * once and never again during a batch.
 *
 * ## Shape
 *
 *     {
 *       "format": "tricab.onboarding.source-snapshot/1",
 *       "sourceSystem": "tricab-training-vercel",
 *       "synthetic": true,
 *       "extractedAtUtc": "2026-09-25T10:00:00Z",
 *       "adminEmails": ["hr@example.test"],
 *       "tables": { "trainees": [ {…}, … ], … }
 *     }
 *
 * `tables` holds every table in {@see SourceSnapshotSchema::TABLES} and no
 * other; each row holds exactly that table's columns. Loading is strict: an
 * unknown table or column, a missing one, or a value of the wrong type refuses
 * the snapshot, naming it. A snapshot is evidence of what the source said, so
 * nothing is repaired on the way in.
 *
 * `adminEmails` is the source's `ADMIN_EMAILS` setting, which lives in the
 * Vercel environment rather than the database; the operator supplies it at
 * extraction. A person is a source admin if `is_admin` is set **or** their
 * address is in it.
 *
 * ## Hash
 *
 * {@see hash()} is SHA-256 over the canonical JSON of `synthetic`,
 * `adminEmails` and `tables` (rows ordered by `id`). It is part of the preview
 * fingerprint: a snapshot that changed after it was previewed cannot be
 * executed on that preview's approval.
 */
final class SourceSnapshot
{
    /**
     * @param array<string, array<int, array<string, mixed>>> $tables table => id => row
     * @param list<string> $adminEmails lower-case
     */
    private function __construct(
        private readonly bool $synthetic,
        private readonly string $extractedAtUtc,
        private readonly array $adminEmails,
        private readonly array $tables,
    ) {
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @throws OnboardingMigrationRefused
     */
    public static function fromArray(array $document): self
    {
        if (($document['format'] ?? null) !== SourceSnapshotSchema::FORMAT) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_FORMAT', 'not a ' . SourceSnapshotSchema::FORMAT . ' snapshot');
        }
        if (($document['sourceSystem'] ?? null) !== SourceSnapshotSchema::SOURCE_SYSTEM) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_FORMAT', 'the snapshot is not from ' . SourceSnapshotSchema::SOURCE_SYSTEM);
        }
        if (!is_bool($document['synthetic'] ?? null)) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_FORMAT', '"synthetic" must be true or false');
        }
        $extractedAt = $document['extractedAtUtc'] ?? null;
        if (!is_string($extractedAt) || self::timestamp($extractedAt) === null) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_FORMAT', '"extractedAtUtc" is not a UTC timestamp');
        }

        $admins = [];
        foreach (is_array($document['adminEmails'] ?? null) ? $document['adminEmails'] : [] as $email) {
            if (!is_string($email) || !str_contains($email, '@')) {
                throw OnboardingMigrationRefused::because('SNAPSHOT_FORMAT', '"adminEmails" holds something that is not an address');
            }
            $admins[] = strtolower(trim($email));
        }
        $admins = array_values(array_unique($admins));
        sort($admins);

        $rawTables = $document['tables'] ?? null;
        if (!is_array($rawTables)) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_FORMAT', 'no "tables"');
        }
        $unknown = array_diff(array_keys($rawTables), array_keys(SourceSnapshotSchema::TABLES));
        if ($unknown !== []) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_TABLE', 'tables the migration does not read: ' . implode(', ', $unknown));
        }

        $tables = [];
        foreach (SourceSnapshotSchema::TABLES as $table => $columns) {
            $rows = $rawTables[$table] ?? throw OnboardingMigrationRefused::because('SNAPSHOT_TABLE', 'missing table ' . $table);
            if (!is_array($rows) || !array_is_list($rows)) {
                throw OnboardingMigrationRefused::because('SNAPSHOT_TABLE', $table . ' is not a list of rows');
            }
            $tables[$table] = [];
            foreach ($rows as $index => $row) {
                $typed = self::typedRow($table, $columns, $row, $index);
                $id = $typed['id'];
                if (!is_int($id) || isset($tables[$table][$id])) {
                    throw OnboardingMigrationRefused::because('SNAPSHOT_ROW', sprintf('%s row %d: id missing or repeated', $table, $index + 1));
                }
                $tables[$table][$id] = $typed;
            }
            ksort($tables[$table]);
        }

        return new self($document['synthetic'], $extractedAt, $admins, $tables);
    }

    /**
     * @throws OnboardingMigrationRefused
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_FORMAT', 'not JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_FORMAT', 'not a JSON object');
        }

        return self::fromArray($decoded);
    }

    public function isSynthetic(): bool
    {
        return $this->synthetic;
    }

    public function extractedAtUtc(): string
    {
        return $this->extractedAtUtc;
    }

    /** @return list<string> */
    public function adminEmails(): array
    {
        return $this->adminEmails;
    }

    /** @return list<array<string, mixed>> in id order */
    public function rows(string $table): array
    {
        return array_values($this->tables[$table] ?? []);
    }

    /** @return array<string, mixed>|null */
    public function row(string $table, int $id): ?array
    {
        return $this->tables[$table][$id] ?? null;
    }

    public function count(string $table): int
    {
        return count($this->tables[$table] ?? []);
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode([
            'synthetic' => $this->synthetic,
            'adminEmails' => $this->adminEmails,
            'tables' => array_map('array_values', $this->tables),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> the document this was read from, canonicalised */
    public function toArray(): array
    {
        return [
            'format' => SourceSnapshotSchema::FORMAT,
            'sourceSystem' => SourceSnapshotSchema::SOURCE_SYSTEM,
            'synthetic' => $this->synthetic,
            'extractedAtUtc' => $this->extractedAtUtc,
            'adminEmails' => $this->adminEmails,
            'tables' => array_map('array_values', $this->tables),
        ];
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, mixed>
     */
    private static function typedRow(string $table, array $columns, mixed $row, int $index): array
    {
        $where = sprintf('%s row %d', $table, $index + 1);
        if (!is_array($row)) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_ROW', $where . ' is not an object');
        }
        $extra = array_diff(array_keys($row), array_keys($columns));
        if ($extra !== []) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_COLUMN', $where . ': columns the migration does not read: ' . implode(', ', $extra));
        }

        $typed = [];
        foreach ($columns as $column => $type) {
            if (!array_key_exists($column, $row)) {
                throw OnboardingMigrationRefused::because('SNAPSHOT_COLUMN', $where . ': missing column ' . $column);
            }
            $typed[$column] = self::value($row[$column], $type, $where . ' ' . $column);
        }

        return $typed;
    }

    private static function value(mixed $value, string $type, string $where): mixed
    {
        if ($value === null) {
            if ($type === SourceSnapshotSchema::ID && str_ends_with($where, ' id')) {
                throw OnboardingMigrationRefused::because('SNAPSHOT_ROW', $where . ' is null');
            }

            return null;
        }

        $ok = match ($type) {
            SourceSnapshotSchema::ID, SourceSnapshotSchema::INT => is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1),
            SourceSnapshotSchema::BOOL => is_bool($value),
            SourceSnapshotSchema::UUID => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            SourceSnapshotSchema::DATE => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            SourceSnapshotSchema::TIMESTAMP => is_string($value) && self::timestamp($value) !== null,
            SourceSnapshotSchema::JSON => is_array($value),
            default => is_string($value),
        };
        if (!$ok) {
            throw OnboardingMigrationRefused::because('SNAPSHOT_ROW', $where . ' is not a ' . $type);
        }

        return match ($type) {
            SourceSnapshotSchema::ID, SourceSnapshotSchema::INT => (int) $value,
            SourceSnapshotSchema::UUID => strtolower((string) $value),
            SourceSnapshotSchema::TIMESTAMP => self::timestamp((string) $value),
            SourceSnapshotSchema::JSON => self::canonicalJson($value),
            default => $value,
        };
    }

    /**
     * Object keys sorted, recursively; lists kept in order. Postgres `jsonb`
     * stores keys in its own order, so without this the same data read twice
     * could hash differently.
     */
    private static function canonicalJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonicalJson(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** Any ISO-8601 instant with an offset, normalised to `Y-m-d\TH:i:s.u\Z`. */
    private static function timestamp(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
