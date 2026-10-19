<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Npr\Persistence\AzureSqlNprRequestRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\NewNprRequest;
use TriCab\Core\Npr\Domain\NprDraftChange;
use TriCab\Core\Npr\Domain\RequestStatus;

/** The SQL the repository sends and how it maps rows, against the fake connection. */
final class AzureSqlNprRequestRepositoryTest extends TestCase
{
    private const ID = '5e8f0a3c-1b2d-4c3e-8f4a-0123456789ab';

    private FakeSqlConnection $connection;
    private AzureSqlNprRequestRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new FakeSqlConnection();
        $this->repository = new AzureSqlNprRequestRepository($this->connection, FixedClock::utc('2026-10-19 03:00:00'));
    }

    /** @return array<string, mixed> */
    private function row(string $status = 'draft', int $revision = 1, string $version = '0x00000000000007D1'): array
    {
        return [
            'PublicId' => strtoupper(self::ID),
            'RequestNumber' => 'NPR-2026-0007',
            'CurrentRevision' => $revision,
            'Status' => $status,
            'ScreeningOutcome' => null,
            'Title' => 'Mining trailing cable',
            'CustomerName' => 'BHP',
            'UrgencyLevel' => 'standard',
            'RequesterUserId' => 10,
            'RequesterEmail' => 'amandaw@tricab.com',
            'RequesterName' => 'Amanda Williams',
            'RequesterBranch' => 'Brisbane',
            'CreatedAt' => '2026-10-19 02:00:00.000',
            'UpdatedAt' => '2026-10-19 02:30:00.000',
            'SubmittedAt' => null,
            'Version' => $version,
            'AnswersJson' => '{"customer_name":{"state":"provided","value":"BHP"}}',
            'VariantsJson' => '[{"id":"var_1","label":"Std","answers":{}}]',
        ];
    }

    private function change(): NprDraftChange
    {
        return new NprDraftChange(10, 'amandaw@tricab.com', 'Amanda Williams', 'T', 'C', 'standard', ['a' => ['state' => 'provided', 'value' => 1]], [], null);
    }

    public function test_find_maps_a_row(): void
    {
        $this->connection->queueFetch([$this->row()]);

        $request = $this->repository->find(strtoupper(self::ID));

        self::assertNotNull($request);
        self::assertSame(self::ID, $request->publicId, 'ids are lower-case');
        self::assertSame('NPR-2026-0007', $request->requestNumber);
        self::assertSame(RequestStatus::Draft, $request->status);
        self::assertSame('0x00000000000007D1', $request->version);
        self::assertSame(10, $request->requesterUserId);
        self::assertSame(['state' => 'provided', 'value' => 'BHP'], $request->answers['customer_name'] ?? null);
        self::assertSame('var_1', $request->variants[0]['id']);
        self::assertSame('2026-10-19T02:30:00Z', $request->updatedAt->format('Y-m-d\TH:i:s\Z'));
        self::assertStringContainsString('CONVERT(VARCHAR(18), CAST(r.[RowVersion] AS BINARY(8)), 1)', $this->connection->calls[0]['sql']);
        self::assertStringContainsString('v.[RevisionNumber] = r.[CurrentRevision]', $this->connection->calls[0]['sql']);
    }

    public function test_a_malformed_id_never_reaches_the_database(): void
    {
        self::assertNull($this->repository->find("x'; DROP TABLE [npr].[Requests];--"));
        self::assertNull($this->repository->saveDraft('nope', '0x01', $this->change()));
        self::assertSame([], $this->connection->calls);
    }

    public function test_requester_list_is_scoped_by_user_id_and_ordered(): void
    {
        $this->connection->queueFetch([$this->row()]);

        $list = $this->repository->listForRequester(10);

        self::assertCount(1, $list);
        self::assertStringContainsString('r.[RequesterUserId] = :userId', $this->connection->calls[0]['sql']);
        self::assertSame(['userId' => 10], $this->connection->calls[0]['params']);
        self::assertStringContainsString('ORDER BY r.[UpdatedAt] DESC', $this->connection->calls[0]['sql']);
    }

    public function test_tsd_list_is_limited_to_with_tsd_and_later(): void
    {
        $this->connection->queueFetch([]);

        $this->repository->listForTsd();

        self::assertStringContainsString(
            "r.[Status] IN (N'with_tsd', N'awaiting_screening', N'screening_completed')",
            $this->connection->calls[0]['sql'],
        );
        self::assertStringContainsString("N'returned_to_sales' AND r.[TsdReceivedAt] IS NOT NULL", $this->connection->calls[0]['sql']);
    }

    public function test_create_numbers_inserts_the_revision_and_logs_activity_in_one_transaction(): void
    {
        $this->connection->queueFetch([['Next' => 7]]);
        $this->connection->queueFetch([['RequestId' => 55, 'PublicId' => strtoupper(self::ID)]]);
        $this->connection->queueFetch([$this->row()]);

        $request = $this->repository->create(new NewNprRequest(10, 'amandaw@tricab.com', 'Amanda Williams', 'Brisbane', 'T', 'C', 'standard', [], [['id' => 'var_1', 'label' => 'Std', 'answers' => []]]));

        self::assertSame(self::ID, $request->publicId);
        self::assertTrue($this->connection->committed);
        self::assertSame('NPR-2026-0007', $this->connection->calls[1]['params']['number']);
        self::assertStringContainsString('INSERT INTO [npr].[Requests]', $this->connection->calls[1]['sql']);
        self::assertStringContainsString('INSERT INTO [npr].[RequestRevisions]', $this->connection->calls[2]['sql']);
        self::assertSame(55, $this->connection->calls[2]['params']['requestId']);
        self::assertSame('{}', $this->connection->calls[2]['params']['answers'], 'empty answers are an object, not a list');
        self::assertSame('[{"id":"var_1","label":"Std","answers":{}}]', $this->connection->calls[2]['params']['variants']);
        self::assertStringContainsString('INSERT INTO [npr].[Activity]', $this->connection->calls[3]['sql']);
        self::assertSame('created_draft', $this->connection->calls[3]['params']['action']);
        self::assertSame('NPR-2026-%', $this->connection->calls[0]['params']['prefix']);
    }

    public function test_saving_a_draft_updates_the_current_revision_in_place(): void
    {
        $this->connection->queueFetch([['RequestId' => 55, 'Status' => 'draft', 'CurrentRevision' => 1, 'Version' => '0x00000000000007D1']]);
        $this->connection->queueFetch([$this->row()]);

        $saved = $this->repository->saveDraft(self::ID, '0x00000000000007d1', $this->change());

        self::assertNotNull($saved);
        self::assertTrue($this->connection->committed);
        self::assertStringContainsString('WITH (UPDLOCK, HOLDLOCK, ROWLOCK)', $this->connection->calls[0]['sql']);
        self::assertStringContainsString('UPDATE [npr].[RequestRevisions]', $this->connection->calls[1]['sql']);
        self::assertStringContainsString('UPDATE [npr].[Requests]', $this->connection->calls[2]['sql']);
        self::assertSame(1, $this->connection->calls[2]['params']['revision']);
        self::assertSame('saved_draft', $this->connection->calls[3]['params']['action']);
        self::assertSame('Saved draft changes', $this->connection->calls[3]['params']['summary']);
    }

    public function test_saving_a_returned_request_inserts_a_new_revision(): void
    {
        $this->connection->queueFetch([['RequestId' => 55, 'Status' => 'returned_to_sales', 'CurrentRevision' => 2, 'Version' => '0x00000000000007D1']]);
        $this->connection->queueFetch([$this->row('returned_to_sales', 3)]);

        $this->repository->saveDraft(self::ID, '0x00000000000007D1', $this->change());

        self::assertStringContainsString('INSERT INTO [npr].[RequestRevisions]', $this->connection->calls[1]['sql']);
        self::assertSame(3, $this->connection->calls[1]['params']['revision']);
        self::assertSame('Amended returned request', $this->connection->calls[1]['params']['summary']);
        self::assertStringNotContainsString('UPDATE [npr].[RequestRevisions]', implode(' ', array_column($this->connection->calls, 'sql')));
        self::assertSame(3, $this->connection->calls[2]['params']['revision']);
    }

    public function test_a_stale_version_writes_nothing_and_rolls_back(): void
    {
        $this->connection->queueFetch([['RequestId' => 55, 'Status' => 'draft', 'CurrentRevision' => 1, 'Version' => '0x00000000000007D2']]);

        try {
            $this->repository->saveDraft(self::ID, '0x00000000000007D1', $this->change());
            self::fail('expected a conflict');
        } catch (NprRequestConflict $conflict) {
            self::assertSame('0x00000000000007D2', $conflict->currentVersion);
        }

        self::assertTrue($this->connection->rolledBack);
        self::assertCount(1, $this->connection->calls, 'only the locking read');
    }

    public function test_a_request_past_editing_is_refused_and_rolled_back(): void
    {
        $this->connection->queueFetch([['RequestId' => 55, 'Status' => 'with_tsd', 'CurrentRevision' => 1, 'Version' => '0x00000000000007D1']]);

        $this->expectException(NprRequestNotEditable::class);
        try {
            $this->repository->saveDraft(self::ID, '0x00000000000007D1', $this->change());
        } finally {
            self::assertTrue($this->connection->rolledBack);
            self::assertCount(1, $this->connection->calls);
        }
    }

    public function test_saving_a_missing_request_is_null(): void
    {
        $this->connection->queueFetch([]);

        self::assertNull($this->repository->saveDraft(self::ID, '0x01', $this->change()));
        self::assertCount(1, $this->connection->calls, 'only the locking read');
    }
}
