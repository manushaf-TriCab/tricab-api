<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding\Migration;

use PDO;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\Migration\LocalPostgresSyntheticLoader;
use TriCab\Api\Onboarding\Migration\PostgresSourceSnapshotExtractor;
use TriCab\Api\Onboarding\Migration\SyntheticSourceBuilder;
use TriCab\Core\Onboarding\Migration\OnboardingMigrationRefused;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshotSchema;
use TriCab\Core\Onboarding\Migration\Target\TargetCatalogue;

/**
 * @phpstan-import-type SnapshotDocument from SyntheticSourceBuilder
 *
 * Phase 7.1 — the snapshot is a strict, allow-listed, hashable copy of the
 * source, and the extractor reads Postgres without being able to write to it.
 *
 * The Postgres tests run against a local database holding the source's own
 * `db/schema.sql` (a throwaway container), named by
 * `ONBOARDING_MIGRATION_TEST_POSTGRES_URL`; they are skipped without one.
 */
final class SourceSnapshotTest extends TestCase
{
    public function test_a_synthetic_snapshot_loads_and_its_hash_is_stable(): void
    {
        $a = SourceSnapshot::fromArray($this->synthetic());
        $b = SourceSnapshot::fromArray($this->synthetic());

        self::assertTrue($a->isSynthetic());
        self::assertSame($a->hash(), $b->hash());
        self::assertSame(6, $a->count('trainees'));
        self::assertSame(['hr.lead@example.test'], $a->adminEmails());
    }

    public function test_json_key_order_does_not_change_the_hash(): void
    {
        $doc = $this->synthetic();
        $declaration = $doc['tables']['policy_acknowledgements'][0]['declaration_data'];
        self::assertIsArray($declaration);

        self::assertSame(
            SourceSnapshot::fromArray($doc)->hash(),
            SourceSnapshot::fromArray(self::withValue($doc, 'policy_acknowledgements', 0, 'declaration_data', array_reverse($declaration, true)))->hash(),
        );
    }

    public function test_a_changed_row_changes_the_hash(): void
    {
        $doc = $this->synthetic();

        self::assertNotSame(
            SourceSnapshot::fromArray($doc)->hash(),
            SourceSnapshot::fromArray(self::withValue($doc, 'assignments', 0, 'status', 'in_progress'))->hash(),
        );
    }

    public function test_a_table_or_column_off_the_allow_list_is_refused(): void
    {
        $doc = $this->synthetic();
        $withOtp = $doc;
        $withOtp['tables']['otp_challenges'] = [];
        $withoutAnswers = $doc;
        unset($withoutAnswers['tables']['attempts'][0]['answers']);
        $withoutQuestions = $doc;
        unset($withoutQuestions['tables']['questions']);
        $wrongFormat = $doc;
        $wrongFormat['format'] = 'something-else';

        foreach ([
            'otp table' => $withOtp,
            'extra column' => self::withValue($doc, 'trainees', 0, 'profile_photo_base64', 'AAAA'),
            'missing column' => $withoutAnswers,
            'missing table' => $withoutQuestions,
            'wrong type' => self::withValue($doc, 'trainees', 0, 'is_admin', 'yes'),
            'bad timestamp' => self::withValue($doc, 'assignments', 0, 'assigned_at', '25/09/2026'),
            'repeated id' => self::withValue($doc, 'modules', 1, 'id', 1),
            'wrong format' => $wrongFormat,
        ] as $case => $bad) {
            try {
                SourceSnapshot::fromArray($bad);
                self::fail('Accepted: ' . $case);
            } catch (OnboardingMigrationRefused) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param SnapshotDocument $doc
     *
     * @return SnapshotDocument
     */
    private static function withValue(array $doc, string $table, int $row, string $column, mixed $value): array
    {
        $doc['tables'][$table][$row][$column] = $value;

        return $doc;
    }

    public function test_the_extractor_selects_only_allow_listed_columns_in_a_portable_form(): void
    {
        $sql = PostgresSourceSnapshotExtractor::selectFor('policy_acknowledgements', SourceSnapshotSchema::TABLES['policy_acknowledgements']);

        self::assertStringStartsWith('SELECT ', $sql);
        self::assertStringContainsString('"declaration_data"::text AS "declaration_data"', $sql);
        self::assertStringContainsString("to_char(\"acknowledged_at\" AT TIME ZONE 'UTC'", $sql);
        self::assertStringEndsWith('FROM "public"."policy_acknowledgements" ORDER BY "id"', $sql);

        $this->expectException(OnboardingMigrationRefused::class);
        PostgresSourceSnapshotExtractor::selectFor('otp_challenges', ['code_hash' => SourceSnapshotSchema::TEXT]);
    }

    public function test_the_loader_refuses_anything_but_this_machine(): void
    {
        $this->expectException(OnboardingMigrationRefused::class);
        LocalPostgresSyntheticLoader::connect('postgres://u:p@ep-example.neon.tech/neondb?sslmode=require');
    }

    public function test_generate_load_extract_gives_back_the_same_snapshot(): void
    {
        $url = $this->postgresUrl();
        $generated = SourceSnapshot::fromArray($this->synthetic());

        LocalPostgresSyntheticLoader::connect($url)->load($generated);
        $extracted = SourceSnapshot::fromArray(
            PostgresSourceSnapshotExtractor::connect($url)->extract(true, $generated->adminEmails(), new \DateTimeImmutable()),
        );

        self::assertSame($generated->hash(), $extracted->hash());
    }

    public function test_the_extractor_s_transaction_cannot_write(): void
    {
        $pdo = PostgresSourceSnapshotExtractor::openPdo($this->postgresUrl());
        $pdo->exec('BEGIN TRANSACTION READ ONLY');
        try {
            $pdo->exec("UPDATE \"public\".\"trainees\" SET \"full_name\" = 'changed'");
            self::fail('A write succeeded inside a read-only transaction.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('read-only transaction', $e->getMessage());
        } finally {
            $pdo->exec('ROLLBACK');
        }
    }

    /** @return SnapshotDocument */
    private function synthetic(): array
    {
        return (new SyntheticSourceBuilder(self::catalogue()))->build(new \DateTimeImmutable('2026-09-25T00:00:00Z'));
    }

    private function postgresUrl(): string
    {
        $url = getenv('ONBOARDING_MIGRATION_TEST_POSTGRES_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('ONBOARDING_MIGRATION_TEST_POSTGRES_URL is not set (a local Postgres with the source schema).');
        }
        return $url;
    }

    /** The shape of what is live on tricab-mel, without reading it. */
    public static function catalogue(): TargetCatalogue
    {
        $module = static fn (string $slug, int $slides, int $questions, int $pass): array => [
            'slug' => $slug, 'versionId' => crc32($slug) % 1000, 'versionPublicId' => md5($slug), 'versionNumber' => 1,
            'slideIds' => array_map(static fn (int $i): string => sprintf('slide-%03d', $i), range(1, $slides)),
            'passMarkPercent' => $pass,
            'questions' => array_map(static fn (int $i): array => [
                'questionId' => $i, 'ordinal' => $i, 'prompt' => $slug . ' question ' . $i,
                'optionKeys' => ['a', 'b', 'c', 'd'], 'correctOptionKey' => ['a', 'b', 'c', 'd'][$i % 4],
            ], range(1, $questions)),
        ];
        $policy = static fn (string $slug, ?string $ref, bool $ack): array => [
            'slug' => $slug, 'versionId' => crc32($slug) % 1000, 'versionPublicId' => md5('p' . $slug), 'versionNumber' => 1,
            'documentReference' => $ref, 'requiresAcknowledgement' => $ack,
        ];

        return new TargetCatalogue(
            [
                'corporate-whse-induction' => $module('corporate-whse-induction', 88, 17, 80),
                'demo-forklift-awareness' => $module('demo-forklift-awareness', 3, 1, 100),
                'demo-site-induction' => $module('demo-site-induction', 4, 2, 80),
            ],
            [
                'employee-declaration' => $policy('employee-declaration', null, true),
                'code-of-conduct' => $policy('code-of-conduct', 'HR-POL-036-V000', true),
                'company-vehicle-policy' => $policy('company-vehicle-policy', 'HR-POL-010-V003', true),
                'workplace-health-and-safety-policy' => $policy('workplace-health-and-safety-policy', 'WHS-POL-001-V005', true),
                'workplace-behaviour-policy' => $policy('workplace-behaviour-policy', 'HR-POL-033-V008', true),
                'demo-whs-policy' => $policy('demo-whs-policy', null, true),
                'handbook-welcome' => $policy('handbook-welcome', null, false),
            ],
            [
                'demo-starter-details' => self::formFixture('demo-starter-details', [
                    ['path' => 'preferredName', 'type' => 'text', 'required' => true],
                    ['path' => 'startDate', 'type' => 'date', 'required' => true],
                    ['path' => 'parking', 'type' => 'select', 'options' => [['key' => 'yes'], ['key' => 'no']]],
                    ['path' => 'notes', 'type' => 'textarea'],
                    ['path' => 'confirm', 'type' => 'checkbox', 'required' => true],
                ]),
                'demo-uniform-request' => self::formFixture('demo-uniform-request', [
                    ['path' => 'shirtSize', 'type' => 'select', 'required' => true, 'options' => [['key' => 's'], ['key' => 'm'], ['key' => 'l']]],
                    ['path' => 'bootSize', 'type' => 'number', 'required' => true],
                ]),
            ],
        );
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return array{slug: string, versionId: int, versionPublicId: string, versionNumber: int, accessGroup: string, fields: list<array<string, mixed>>, classifications: array<string, int>}
     */
    private static function formFixture(string $slug, array $fields): array
    {
        $classifications = [];
        foreach ($fields as $field) {
            if (is_string($field['path'] ?? null)) {
                $classifications[$field['path']] = 2;
            }
        }

        return [
            'slug' => $slug, 'versionId' => crc32($slug) % 1000, 'versionPublicId' => md5('f' . $slug), 'versionNumber' => 1,
            'accessGroup' => 'hr', 'fields' => $fields, 'classifications' => $classifications,
        ];
    }
}
