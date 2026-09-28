<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqRepository;
use TriCab\Core\Rfq\Application\DTO\RfqQueueQuery;
use TriCab\Core\Rfq\Application\DTO\RfqRecord;
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Domain\ValueObject\IntakeChannel;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Plan 2.4, the read side: the queue and the record from the store's tables.
 *
 * The property that matters most is that **the query and the PHP policy
 * agree**: for every kind of actor, the rows returned are exactly the drafts
 * `RfqAccessPolicy::canSee()` allows (every one, for anyone who reads), and
 * the total counts those. The rest pins the mapping from the source's columns
 * and the filters, branch among them.
 *
 * Needs a local SQL Server ({@see LocalRfqSqlServer}); skips without one.
 */
#[Group('local-sql-server')]
final class AzureSqlRfqRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AzureSqlRfqRepository $repository;
    private AzureSqlRfqDraftStore $store;

    /** @var array<string, int> */
    private array $users = [];

    /** @var array<string, string> name => draft id */
    private array $drafts = [];

    protected function setUp(): void
    {
        $pdo = LocalRfqSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set RFQ_TEST_SQLSERVER and RFQ_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalRfqSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->repository = new AzureSqlRfqRepository($connection);
        $this->store = new AzureSqlRfqDraftStore($connection, new SystemClock());
        $this->seed();
    }

    public function testTheQueryAdmitsExactlyWhatThePolicyAllowsForEveryActor(): void
    {
        $actors = [
            'no permission' => $this->actor('alice', [Module::PERMISSION_ACCESS], 'VIC'),
            'employee (alice)' => $this->actor('alice', Module::EMPLOYEE_PERMISSIONS, null),
            'employee (dave, unverified alias only)' => $this->actor('dave', Module::EMPLOYEE_PERMISSIONS, null),
            'sales in VIC (bob)' => $this->actor('bob', Module::ROLE_PERMISSIONS[Module::ROLE_SALES], 'VIC'),
            'admin (erin)' => $this->actor('erin', Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN], null),
        ];
        $all = $this->allRecords();
        self::assertCount(count($this->drafts), $all);

        foreach ($actors as $label => $actor) {
            foreach ([RfqQueueQuery::ARCHIVED_INCLUDE, RfqQueueQuery::ARCHIVED_EXCLUDE] as $archived) {
                $query = new RfqQueueQuery('', [], [], $archived, false, 1, 100);
                $expected = array_values(array_filter(
                    $all,
                    static fn (RfqRecord $r): bool => RfqAccessPolicy::canSee($actor, $r->ownership)
                        && ($archived === RfqQueueQuery::ARCHIVED_INCLUDE || !$r->ownership->archived),
                ));
                $page = $this->repository->pageVisibleTo($actor, $query);

                self::assertSame(self::ids($expected), self::ids($page['items']), "{$label}, archived={$archived}");
                self::assertSame(count($expected), $page['total'], "{$label} total");
            }
        }
        self::assertCount(0, $this->repository->pageVisibleTo($actors['no permission'], RfqQueueQuery::defaults())['items']);
        self::assertSame(count($this->drafts), $this->repository->pageVisibleTo($actors['employee (dave, unverified alias only)'], new RfqQueueQuery('', [], [], RfqQueueQuery::ARCHIVED_INCLUDE, false, 1, 100))['total'], 'every RFQ, for every reader');
        // And the service's own re-check never fires.
        foreach ($actors as $actor) {
            (new ReadRfqQueueService($this->repository))->page($actor, RfqQueueQuery::defaults());
        }
    }

    public function testRecordsReadTheSourceColumnsAsTheContractsSayAndCarryNoEmail(): void
    {
        $record = $this->record('email');
        self::assertSame(StoreProjection::publicId($this->drafts['email']), $record->publicId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $record->publicId);
        self::assertSame('RFQ-' . strtoupper(substr($this->drafts['email'], 0, 8)), $record->reference);
        self::assertSame('Metro tunnel', $record->title);
        self::assertSame('C100', $record->customerCode);
        self::assertSame('Metro Electrical 100% Pty', $record->customerName);
        self::assertTrue($record->channel->is(IntakeChannel::EMAIL));
        self::assertSame($this->users['alice'], $record->ownership->ownerUserId);
        self::assertSame('Alice A', $record->ownerDisplayName);
        self::assertSame('Carol C', $record->assigneeDisplayName, 'assignee matched through a verified second address');
        self::assertSame(3, $record->lineCount);
        self::assertTrue($record->workflowStatus->is('completed'), 'source synced');
        self::assertSame('succeeded', $record->latestSyncState?->toString(), 'the latest of two attempts');
        self::assertSame('4J123', $record->quoteNumber);
        self::assertSame('2026-07-11 20:52:03', $record->dueAtUtc?->format('Y-m-d H:i:s'), 'the July due_at shape');
        self::assertTrue($record->extractionState->is(ExtractionState::ATTENTION), 'a done read still carrying a proposal');
        self::assertStringNotContainsString('@', json_encode((new \TriCab\Core\Rfq\Application\DTO\RfqSummary($record, []))->toArray(), JSON_THROW_ON_ERROR));

        self::assertTrue($this->record('alternative')->channel->is(IntakeChannel::WEB), 'staff tools are the web');
        self::assertTrue($this->record('unassigned')->extractionState->is(ExtractionState::READING));
        self::assertTrue($this->record('mine')->extractionState->is(ExtractionState::PARTIAL), 'coverage is not measured until plan 3.4');
        self::assertNull($this->record('ambiguous')->ownership->ownerUserId, 'an address two people claim belongs to nobody');
        self::assertNull($this->record('ambiguous')->ownerDisplayName);
        self::assertNull($this->record('dave')->ownership->ownerUserId, 'an unverified address grants nothing');
        self::assertTrue($this->record('pending')->workflowStatus->is('new'), 'source alias pending');
    }

    public function testFiltersNarrowAsTheInMemoryRulesDo(): void
    {
        $all = $this->actor('erin', Module::EMPLOYEE_PERMISSIONS, null);
        $names = fn (RfqQueueQuery $q): array => $this->names($this->repository->pageVisibleTo($all, $q)['items']);
        $q = self::query(...);

        self::assertSame(['email'], $names($q('metro electrical')), 'customer name, any case');
        self::assertSame(['email'], $names($q('100%')), '% is literal, not a wildcard');
        self::assertSame([], $names($q('1_0')), '_ is literal');
        self::assertSame(['email'], $names($q('4j123')), 'the 4js quote number');
        self::assertSame(['email'], $names($q(StoreProjection::reference($this->drafts['email']))), 'the reference');
        self::assertSame(['email'], $names($q('', ['completed'])));
        $new = $names($q('', ['new']));
        self::assertContains('pending', $new, 'new includes the source alias pending');
        self::assertContains('ambiguous', $new, 'and an empty status');
        self::assertNotContains('email', $new);
        self::assertSame(['urgent'], $names($q('', [], ['urgent'])));
        self::assertSame(['archived'], $names($q('', [], [], RfqQueueQuery::ARCHIVED_ONLY)));
        self::assertNotContains('archived', $names($q()));

        self::assertSame(['nsw'], $names(RfqQueueQuery::fromQueryParams(['branch' => 'NSW'])));
        self::assertSame(['mine', 'urgent', 'vic'], self::sortedNames($names(RfqQueueQuery::fromQueryParams(['branch' => 'vic']))), 'branch compared upper-cased; archived excluded by default');
        $none = $names(RfqQueueQuery::fromQueryParams(['branch' => 'none', 'pageSize' => '100']));
        self::assertContains('unassigned', $none);
        self::assertNotContains('nsw', $none);
        self::assertSame(['nsw', 'unassigned'], self::sortedNames(array_values(array_intersect($names(RfqQueueQuery::fromQueryParams(['branch' => 'NSW,none', 'pageSize' => '100'])), ['nsw', 'unassigned', 'vic']))));
        self::assertSame(['NSW', 'VIC'], $this->repository->branches());

        $carol = $this->actor('carol', Module::EMPLOYEE_PERMISSIONS, null);
        self::assertSame(['email'], $this->names($this->repository->pageVisibleTo($carol, $q('', [], [], RfqQueueQuery::ARCHIVED_EXCLUDE, true))['items']), 'mine = assigned to me');
    }

    public function testOrderIsMostPressingThenNewestAndPagesCountTheWhole(): void
    {
        $all = $this->actor('erin', Module::EMPLOYEE_PERMISSIONS, null);
        $every = $this->repository->pageVisibleTo($all, new RfqQueueQuery('', [], [], RfqQueueQuery::ARCHIVED_INCLUDE, false, 1, 100));
        self::assertSame('urgent', $this->names($every['items'])[0]);
        $ranks = array_map(static fn (RfqRecord $r): int => $r->priority->rank(), $every['items']);
        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks);

        $page2 = $this->repository->pageVisibleTo($all, new RfqQueueQuery('', [], [], RfqQueueQuery::ARCHIVED_INCLUDE, false, 2, 3));
        self::assertSame(array_slice(self::inOrder($every['items']), 3, 3), self::inOrder($page2['items']));
        self::assertSame($every['total'], $page2['total']);
        $beyond = $this->repository->pageVisibleTo($all, new RfqQueueQuery('', [], [], RfqQueueQuery::ARCHIVED_INCLUDE, false, 50, 3));
        self::assertSame([], $beyond['items']);
        self::assertSame($every['total'], $beyond['total'], 'past the last page the total still counts');
    }

    public function testFindRecordTakesOnlyAWellFormedPublicId(): void
    {
        self::assertNotNull($this->repository->findRecord(StoreProjection::publicId($this->drafts['mine'])));
        self::assertNull($this->repository->findRecord($this->drafts['mine']), 'the raw draft id is not a public id');
        self::assertNull($this->repository->findRecord('00000000-0000-4000-8000-000000000000'));
        self::assertNull($this->repository->findRecord("x' OR 1=1 --"));
    }

    private function seed(): void
    {
        foreach (['alice' => 'Alice A', 'bob' => 'Bob B', 'carol' => 'Carol C', 'dave' => 'Dave D', 'erin' => 'Erin E'] as $name => $display) {
            $this->users[$name] = $this->user("{$name}@tricab.com", $display);
        }
        $this->alias('carol', 'carol.alt@tricab.com', verified: true);
        $this->alias('dave', 'dave.alt@tricab.com', verified: false);
        $this->alias('bob', 'shared@tricab.com', verified: true);
        $this->user('shared@tricab.com', 'Somebody Else'); // primary of one, verified alias of another

        $this->make('email', 'email', 'Alice@TriCab.com', 'carol.alt@tricab.com', '', 3, ['customer_code' => 'C100', 'customer_name' => 'Metro Electrical 100% Pty'], 'Metro tunnel');
        $this->make('mine', 'web', 'alice@tricab.com', null, 'VIC', 1);
        $this->make('vic', 'web', 'erin@tricab.com', '', 'vic', 1);
        $this->make('nsw', 'web', 'erin@tricab.com', '', 'NSW', 1);
        $this->make('alternative', 'alternative', 'erin@tricab.com', null, '', 1);
        $this->make('unassigned', 'web', '', '', '', 1);
        $this->make('ambiguous', 'web', 'shared@tricab.com', '', '', 1);
        $this->make('dave', 'web', 'dave.alt@tricab.com', '', '', 1);
        $this->make('archived', 'web', 'alice@tricab.com', null, 'VIC', 1);
        $this->make('urgent', 'web', 'bob@tricab.com', null, 'VIC', 1);
        $this->make('pending', 'web', 'erin@tricab.com', null, '', 1);

        $set = fn (string $name, string $assignments) => $this->pdo->exec("UPDATE [rfq].[rfq_draft_quotes] SET {$assignments} WHERE [id] = '{$this->drafts[$name]}'");
        $set('email', "[status] = N'synced', [refno] = N'4J123', [due_at] = N'2026-07-11 20:52:03'");
        $set('archived', "[archived_at] = N'2026-09-01T00:00:00+00:00'");
        $set('urgent', "[priority] = N'Urgent '");
        $set('pending', "[status] = N'pending'");
        $set('ambiguous', "[status] = N''");

        $attempt = fn (string $name, string $state, string $at) => $this->pdo->exec(
            "INSERT INTO [rfq].[rfq_sync_attempts] ([id], [draft_id], [created_at], [state], [payload], [payload_sha256], [attempt_no])
             VALUES ('" . bin2hex(random_bytes(16)) . "', '{$this->drafts[$name]}', N'{$at}', N'{$state}', N'{}', REPLICATE('0', 64), 1)",
        );
        $attempt('email', 'failed', '2026-09-01T00:00:00+00:00');
        $attempt('email', 'succeeded', '2026-09-02T00:00:00+00:00');

        $read = fn (string $name, string $status, ?string $proposal) => $this->pdo->exec(
            "INSERT INTO [rfq].[rfq_reader_jobs] ([id], [created_at], [draft_id], [file_name], [spool_path], [status], [proposal])
             VALUES ('" . bin2hex(random_bytes(16)) . "', N'2026-09-01T00:00:00+00:00', '{$this->drafts[$name]}', N'a.pdf', N'/tmp/a', N'{$status}', "
            . ($proposal === null ? 'NULL' : "N'{$proposal}'") . ')',
        );
        $read('email', 'done', '{"rows": [1]}');
        $read('mine', 'done', null);
        $read('unassigned', 'queued', null);
    }

    /**
     * @param list<string> $statuses
     * @param list<string> $priorities
     */
    private static function query(string $search = '', array $statuses = [], array $priorities = [], string $archived = RfqQueueQuery::ARCHIVED_EXCLUDE, bool $mine = false): RfqQueueQuery
    {
        return RfqQueueQuery::fromQueryParams(array_filter([
            'q' => $search,
            'status' => implode(',', $statuses),
            'priority' => implode(',', $priorities),
            'archived' => $archived,
            'mine' => $mine ? '1' : '',
            'pageSize' => '100',
        ]));
    }

    /**
     * @param list<RfqRecord> $items
     *
     * @return list<string> public ids, in the order given
     */
    private static function inOrder(array $items): array
    {
        return array_map(static fn (RfqRecord $r): string => $r->publicId, $items);
    }

    /** @param array<string, mixed> $header */
    private function make(string $name, string $source, string $owner, ?string $assignee, string $branch, int $lines, array $header = [], string $label = ''): void
    {
        $rows = array_map(static fn (int $i): array => ['raw_text' => "line {$i}"], range(1, $lines));
        $this->drafts[$name] = $this->store->create(new NewDraft($source, $label !== '' ? $label : $name, $header, $rows, null, $owner, $branch, $assignee));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sortedNames(array $names): array
    {
        sort($names);

        return $names;
    }

    private function user(string $email, string $display): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
             OUTPUT INSERTED.[UserId] VALUES (?, ?, ?, 1)',
        );
        $statement->execute([$email, strtolower($email), $display]);

        return (int) $statement->fetchColumn();
    }

    private function alias(string $user, string $email, bool $verified): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [identity].[UserEmailAddresses] ([UserId], [EmailAddress], [NormalizedEmailAddress], [IsActive], [VerifiedAtUtc])
             VALUES (?, ?, ?, 1, ' . ($verified ? 'SYSUTCDATETIME()' : 'NULL') . ')',
        );
        $statement->execute([$this->users[$user], $email, strtolower($email)]);
    }

    /** @param list<string> $permissions */
    private function actor(string $user, array $permissions, ?string $branch): RfqActor
    {
        return new RfqActor($this->users[$user], "public-{$user}", $permissions, $branch);
    }

    /** @return list<RfqRecord> */
    private function allRecords(): array
    {
        $records = [];
        foreach ($this->drafts as $id) {
            $record = $this->repository->findRecord(StoreProjection::publicId($id));
            self::assertNotNull($record);
            $records[] = $record;
        }

        return $records;
    }

    private function record(string $name): RfqRecord
    {
        $record = $this->repository->findRecord(StoreProjection::publicId($this->drafts[$name]));
        self::assertNotNull($record);

        return $record;
    }

    /**
     * @param list<RfqRecord> $records
     *
     * @return list<string>
     */
    private static function ids(array $records): array
    {
        $ids = array_map(static fn (RfqRecord $r): string => $r->publicId, $records);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<RfqRecord> $records
     *
     * @return list<string>
     */
    private function names(array $records): array
    {
        $byPublic = array_flip(array_map(StoreProjection::publicId(...), $this->drafts));

        return array_map(static fn (RfqRecord $r): string => (string) $byPublic[$r->publicId], $records);
    }
}
