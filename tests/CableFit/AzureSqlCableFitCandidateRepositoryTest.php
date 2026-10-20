<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CableFit\Persistence\AzureSqlCableFitCandidateRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\CableFit\Domain\CandidateRevision;
use TriCab\Core\CableFit\Domain\CandidateSearch;
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

    public function test_search_counts_then_pages_newest_first_with_the_authors_and_the_current_revision(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['total' => 7]]);
        $connection->queueFetch([self::candidateRow(2) + [
            'created_by_name' => 'Chabilal',
            'created_by_email' => 'chabilal@example.test',
            'updated_by_name' => null,
            'updated_by_email' => null,
            'npr_revision_number' => 3,
            'stale' => 1,
        ]]);

        $page = (new AzureSqlCableFitCandidateRepository($connection))->search(new CandidateSearch(null, [], 5, 10));

        self::assertSame(7, $page->total);
        self::assertCount(1, $page->rows);
        $row = $page->rows[0];
        self::assertSame(strtolower(self::ID), $row->candidate->publicId);
        self::assertSame('Chabilal', $row->createdByName);
        self::assertSame('chabilal@example.test', $row->createdByEmail);
        self::assertNull($row->updatedByName);
        self::assertSame(3, $row->sourceNprRevision);
        self::assertTrue($row->storedStale);
        self::assertStringNotContainsString('WHERE', $connection->calls[0]['sql']);
        $sql = $connection->calls[1]['sql'];
        self::assertStringContainsString('LEFT JOIN [identity].[Users] cu ON cu.[UserId] = c.[created_by_user_id]', $sql);
        self::assertStringContainsString('LEFT JOIN [identity].[Users] uu ON uu.[UserId] = c.[updated_by_user_id]', $sql);
        self::assertStringContainsString('r.[revision_number] = c.[current_revision_number]', $sql);
        self::assertStringContainsString('ORDER BY c.[updated_at] DESC, c.[candidate_id] DESC', $sql);
        self::assertStringContainsString('OFFSET 10 ROWS FETCH NEXT 5 ROWS ONLY', $sql);
    }

    public function test_search_text_matches_the_name_with_wildcards_escaped_or_the_given_npr_requests(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['total' => 0]]);

        $page = (new AzureSqlCableFitCandidateRepository($connection))->search(new CandidateSearch('50%_[x]~', ['npr-a', 'npr-b'], 50, 0));

        self::assertSame(0, $page->total);
        self::assertSame([], $page->rows);
        self::assertCount(1, $connection->calls, 'no page query when nothing matches');
        $call = $connection->calls[0];
        self::assertStringContainsString("WHERE (c.[candidate_name] LIKE :text ESCAPE N'~' OR c.[npr_request_public_id] IN (:npr0, :npr1))", $call['sql']);
        self::assertSame('%50~%~_~[x]~~%', $call['params']['text']);
        self::assertSame('npr-a', $call['params']['npr0']);
        self::assertSame('npr-b', $call['params']['npr1']);
    }

    public function test_search_text_with_no_matching_requests_filters_on_the_name_alone(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['total' => 0]]);

        (new AzureSqlCableFitCandidateRepository($connection))->search(new CandidateSearch('drag', [], 50, 0));

        self::assertStringContainsString("WHERE (c.[candidate_name] LIKE :text ESCAPE N'~')", $connection->calls[0]['sql']);
    }

    public function test_linked_npr_requests_are_distinct_and_non_null(): void
    {
        $connection = new FakeSqlConnection([['npr_request_public_id' => 'npr-a'], ['npr_request_public_id' => 'npr-b']]);

        self::assertSame(['npr-a', 'npr-b'], (new AzureSqlCableFitCandidateRepository($connection))->linkedNprRequestPublicIds());
        self::assertStringContainsString('SELECT DISTINCT [npr_request_public_id]', $connection->calls[0]['sql']);
        self::assertStringContainsString('IS NOT NULL', $connection->calls[0]['sql']);
    }
}
