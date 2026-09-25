<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use PDO;
use TriCab\Core\Onboarding\Migration\OnboardingMigrationRefused;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshotSchema;

/**
 * Loads a **synthetic** snapshot into a local Postgres that holds the source's
 * own `db/schema.sql`, so the extractor can be exercised against real Postgres
 * (Phase 7.1 rehearsal). The round trip — generate, load, extract — must give
 * back the same snapshot hash.
 *
 * It writes, so it is fenced: it refuses any host but this machine, and any
 * snapshot not marked synthetic. It fills the source's NOT NULL columns the
 * migration does not read (template paths, presentation metadata, the photo
 * bytes) with placeholders that satisfy the source's CHECK constraints.
 */
final readonly class LocalPostgresSyntheticLoader
{
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /** JSONB columns the source constrains to objects. */
    private const OBJECT_COLUMNS = ['draft_data', 'submitted_data', 'declaration_data'];

    public function __construct(private PDO $pdo)
    {
    }

    public static function connect(string $databaseUrl): self
    {
        $host = parse_url($databaseUrl, PHP_URL_HOST);
        if (!in_array($host, self::LOCAL_HOSTS, true)) {
            throw OnboardingMigrationRefused::because('NOT_LOCAL', 'the synthetic loader writes only to a Postgres on this machine');
        }
        return new self(PostgresSourceSnapshotExtractor::openPdo($databaseUrl));
    }

    public function load(SourceSnapshot $snapshot): void
    {
        if (!$snapshot->isSynthetic()) {
            throw OnboardingMigrationRefused::because('NOT_SYNTHETIC', 'the loader takes synthetic snapshots only');
        }
        $this->pdo->beginTransaction();
        try {
            $tables = array_keys(SourceSnapshotSchema::TABLES);
            $this->pdo->exec('TRUNCATE ' . implode(', ', array_map(static fn (string $t): string => '"public"."' . $t . '"', [...$tables, 'otp_challenges', 'notification_log', 'cohorts'])) . ' RESTART IDENTITY CASCADE');
            foreach ($tables as $table) {
                foreach ($snapshot->rows($table) as $row) {
                    $this->insert($table, $row + self::placeholders($table, $row));
                }
                $this->pdo->exec(sprintf("SELECT setval(pg_get_serial_sequence('\"public\".\"%s\"', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM \"public\".\"%s\"), 1))", $table, $table));
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO "public"."%s" (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        foreach ($row as $column => $value) {
            $type = SourceSnapshotSchema::TABLES[$table][$column] ?? SourceSnapshotSchema::TEXT;
            if ($value === null) {
                $statement->bindValue(':' . $column, null, PDO::PARAM_NULL);
            } elseif ($type === SourceSnapshotSchema::JSON || is_array($value)) {
                // PHP has one empty array; the source's object columns need `{}`.
                $json = $value === [] && in_array($column, self::OBJECT_COLUMNS, true) ? '{}' : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $statement->bindValue(':' . $column, $json);
            } elseif (is_bool($value)) {
                $statement->bindValue(':' . $column, $value, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $statement->bindValue(':' . $column, $value, PDO::PARAM_INT);
            } else {
                $statement->bindValue(':' . $column, is_scalar($value) ? (string) $value : '');
            }
        }
        $statement->execute();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function placeholders(string $table, array $row): array
    {
        $id = is_int($row['id'] ?? null) ? $row['id'] : 0;

        return match ($table) {
            'trainees' => $row['profile_photo_sha256'] === null ? [] : [
                'profile_photo_base64' => base64_encode('synthetic'),
                'profile_photo_filename' => 'synthetic.jpg',
                'profile_photo_consent_at' => $row['profile_photo_uploaded_at'],
            ],
            'modules' => $row['material_type'] === 'presentation' ? [
                'presentation_manifest_url' => '/training-decks/synthetic/manifest.json',
                'presentation_content_hash' => hash('sha256', 'synthetic:' . $id),
                'presentation_slide_count' => count(is_array($row['presentation_slide_ids']) ? $row['presentation_slide_ids'] : []),
            ] : [],
            'form_definitions' => ['template_path' => 'form-templates/synthetic.pdf', 'document_reference' => 'SYN-FORM-' . $id],
            'presentation_progress' => [
                'content_hash' => hash('sha256', 'synthetic-progress:' . $id),
                'manifest_url' => '/training-decks/synthetic/manifest.json',
                'slide_count' => max(1, count(is_array($row['viewed_slide_ids']) ? $row['viewed_slide_ids'] : [])),
            ],
            'policy_acknowledgements' => ['version_content_hash' => hash('sha256', 'synthetic-ack:' . $id)],
            default => [],
        };
    }
}
