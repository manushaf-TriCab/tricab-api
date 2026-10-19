<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CableFit\Persistence\AzureSqlCableFitCandidateRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\CableFit\Domain\CandidateRevision;
use TriCab\Core\CableFit\Domain\CandidateVersionConflict;

/** Statement shape and transaction behaviour, against the recording connection double. */
final class AzureSqlCableFitCandidateRepositoryTest extends TestCase
{
    private const ID = 'A1B2C3D4-0000-4000-8000-000000000001';

    private static function revision(int $number): CandidateRevision
    {
        return new CandidateRevision(strtolower(self::ID), $number, ['construction' => ['a' => 1]], '1.0', false, null, new DateTimeImmutable('2026-10-19T00:00:00Z'), 7);
    }

    /** @return array<string, mixed> */
    private static function candidateRow(int $version): array
    {
        return [
            'public_id' => self::ID,
            'npr_request_public_id' => null,
            'npr_variant_id' => null,
            'candidate_name' => 'Name',
            'current_revision_number' => $version,
            'created_at' => '2026-10-19 00:00:00.0000000',
            'updated_at' => '2026-10-19 00:00:00.0000000',
            'created_by_user_id' => 7,
            'updated_by_user_id' => 7,
        ];
    }

    public function test_public_ids_come_back_lowercase(): void
    {
        $connection = new FakeSqlConnection([self::candidateRow(3)]);

        $candidate = (new AzureSqlCableFitCandidateRepository($connection))->findByPublicId(self::ID);

        self::assertNotNull($candidate);
        self::assertSame(strtolower(self::ID), $candidate->publicId);
        self::assertSame(3, $candidate->version);
    }

    public function test_a_malformed_id_is_not_sent_to_the_server(): void
    {
        $connection = new FakeSqlConnection();

        self::assertNull((new AzureSqlCableFitCandidateRepository($connection))->findByPublicId('not-a-guid'));
        self::assertSame([], $connection->calls);
    }

    public function test_append_updates_conditionally_then_inserts_the_revision_in_one_transaction(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['candidate_id' => 11]]);
        $connection->queueFetch([self::candidateRow(2)]);

        $candidate = (new AzureSqlCableFitCandidateRepository($connection))->appendRevision(self::ID, 1, self::revision(2), 7);

        self::assertSame(2, $candidate->version);
        self::assertTrue($connection->committed);
        self::assertStringContainsString('[current_revision_number] = :expected', $connection->calls[0]['sql']);
        self::assertSame(1, $connection->calls[0]['params']['expected']);
        self::assertStringContainsString('INSERT INTO [cablefit].[candidate_revisions]', $connection->calls[1]['sql']);
        self::assertSame(11, $connection->calls[1]['params']['candidateId']);
    }

    public function test_zero_rows_updated_is_a_conflict_carrying_the_current_version_and_nothing_is_inserted(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);
        $connection->queueFetch([self::candidateRow(4)]);

        try {
            (new AzureSqlCableFitCandidateRepository($connection))->appendRevision(self::ID, 1, self::revision(2), 7);
            self::fail('Expected a conflict.');
        } catch (CandidateVersionConflict $conflict) {
            self::assertSame(1, $conflict->expectedVersion);
            self::assertSame(4, $conflict->currentVersion);
        }

        self::assertTrue($connection->rolledBack);
        foreach ($connection->calls as $call) {
            self::assertStringNotContainsString('INSERT', $call['sql']);
        }
    }
}
