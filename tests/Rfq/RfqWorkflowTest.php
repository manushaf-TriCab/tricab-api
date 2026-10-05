<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqPeopleDirectory;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqRepository;
use TriCab\Api\Tests\Rfq\Persistence\LocalRfqSqlServer;
use TriCab\Core\Rfq\Application\DTO\RfqAuditEvent;
use TriCab\Core\Rfq\Application\DTO\RfqPerson;
use TriCab\Core\Rfq\Application\Service\RfqWorkflowService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Shared\Time\Clock;

/**
 * Parity plan E6, through the real service and the real store on SQL Server:
 * each workflow write is one transaction with its activity row, checks the
 * revision, leaves it alone, and the trail reads back newest first with names
 * and no emails.
 *
 * Needs a local SQL Server ({@see LocalRfqSqlServer}); skips without one.
 */
#[Group('local-sql-server')]
final class RfqWorkflowTest extends TestCase
{
    private PDO $pdo;

    private Clock $clock;

    private AzureSqlRfqDraftStore $store;

    private AzureSqlRfqRepository $repository;

    private RfqWorkflowService $service;

    /** @var array<string, array{id: int, public: string}> */
    private array $users = [];

    private string $publicId;

    private string $draftId;

    protected function setUp(): void
    {
        $pdo = LocalRfqSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set RFQ_TEST_SQLSERVER and RFQ_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalRfqSqlServer::truncate($pdo);
        $this->users['rep'] = $this->user('rep@tricab.com', 'Rita Rep', 1);
        $this->users['mgr'] = $this->user('mgr@tricab.com', 'Max Manager', 1);
        $this->users['pat'] = $this->user('pat@tricab.com', 'Pat Pending', 2);
        $this->users['dee'] = $this->user('dee@tricab.com', 'Dee Disabled', 3);

        // Every reading is a second later, so the trail's order never rests on a tie.
        $this->clock = new class implements Clock {
            private \DateTimeImmutable $now;

            public function __construct()
            {
                $this->now = new \DateTimeImmutable('2026-10-02T01:00:00', new \DateTimeZone('UTC'));
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now = $this->now->modify('+1 second');
            }
        };
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlRfqDraftStore($connection, $this->clock);
        $this->repository = new AzureSqlRfqRepository($connection);
        $this->service = new RfqWorkflowService($this->repository, $this->store, new AzureSqlRfqPeopleDirectory($connection));

        $this->draftId = $this->store->create(new NewDraft('web', 'Switchboard', [], [['raw_text' => 'line 1']], null, 'someone@tricab.com', 'VIC', null));
        // A new draft is assigned to its creator; these tests start from an unclaimed one.
        $this->pdo->exec("UPDATE [rfq].[rfq_draft_quotes] SET [assignee_email] = NULL WHERE [id] = '{$this->draftId}'");
        $this->publicId = StoreProjection::publicId($this->draftId);
    }

    public function test_claim_assigns_the_actor_logs_it_and_does_not_bump_the_revision(): void
    {
        $summary = $this->service->claim($this->rep(), 'rep@tricab.com', 'Rita Rep', $this->publicId, 1);

        self::assertSame('Rita Rep', $summary?->toArray()['assignee']);
        self::assertSame(1, $summary->toArray()['revision']);
        self::assertSame('rep@tricab.com', $this->column('assignee_email'));
        $claimed = $this->latest();
        self::assertSame('claimed', $claimed->type);
        self::assertSame(['from' => null, 'to' => 'Rita Rep'], $claimed->detail);
        self::assertSame('Rita Rep', $claimed->actorDisplayName);
        self::assertFalse($claimed->toArray()['system']);
    }

    public function test_a_second_claim_is_refused_and_a_stale_revision_conflicts_without_writing(): void
    {
        $this->service->claim($this->rep(), 'rep@tricab.com', 'Rita Rep', $this->publicId, 1);
        $before = $this->activityCount();

        try {
            $this->store->claimForActor($this->draftId, 1, new DraftActor('mgr@tricab.com', 'admin', null), 'Max');
            self::fail('A second claim must be refused.');
        } catch (RfqDraftNotEditable $e) {
            self::assertSame(RfqDraftNotEditable::ALREADY_ASSIGNED, $e->reason);
        }
        try {
            $this->store->comment($this->draftId, 5, 'late', new DraftActor('rep@tricab.com', 'sales', null));
            self::fail('A stale revision must conflict.');
        } catch (RfqRevisionConflict $e) {
            self::assertSame(1, $e->currentRevision);
            self::assertSame(5, $e->expectedRevision);
        }
        self::assertSame($before, $this->activityCount());
        self::assertFalse($this->store->comment('f' . substr($this->draftId, 1), 1, 'x', new DraftActor('rep@tricab.com', 'sales', null)));
    }

    public function test_a_content_save_moves_the_revision_so_a_stale_workflow_write_conflicts(): void
    {
        $this->store->saveContent($this->draftId, 1, [], [['raw_text' => 'line 1']], 'in_progress', new DraftActor('rep@tricab.com', 'sales', null));

        $this->expectException(RfqRevisionConflict::class);
        $this->service->comment($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'based on revision 1');
    }

    public function test_assign_resolves_an_active_user_by_id_and_logs_names_not_emails(): void
    {
        $this->service->claim($this->rep(), 'rep@tricab.com', 'Rita Rep', $this->publicId, 1);
        $summary = $this->service->assign($this->manager(), 'mgr@tricab.com', $this->publicId, 1, $this->users['mgr']['public']);

        self::assertSame('Max Manager', $summary?->toArray()['assignee']);
        self::assertSame('mgr@tricab.com', $this->column('assignee_email'));
        $assigned = $this->latest();
        self::assertSame('assigned', $assigned->type);
        self::assertSame(['from' => 'Rita Rep', 'to' => 'Max Manager'], $assigned->detail);

        $cleared = $this->service->assign($this->manager(), 'mgr@tricab.com', $this->publicId, 1, null);
        self::assertNull($cleared?->toArray()['assignee']);
        self::assertNull($this->column('assignee_email'));
        self::assertSame(['from' => 'Max Manager', 'to' => null], $this->latest()->detail);
    }

    public function test_assign_refuses_an_unknown_pending_or_disabled_user(): void
    {
        foreach ([$this->users['pat']['public'], $this->users['dee']['public'], '99999999-9999-4999-8999-999999999999', 'not-a-guid'] as $id) {
            try {
                $this->service->assign($this->manager(), 'mgr@tricab.com', $this->publicId, 1, $id);
                self::fail("{$id} must be refused.");
            } catch (InvalidRfqValue) {
            }
        }
        self::assertNull($this->column('assignee_email'));
    }

    public function test_workflow_blocks_with_a_reason_and_the_summary_carries_it_while_blocked(): void
    {
        $this->service->claim($this->rep(), 'rep@tricab.com', 'Rita Rep', $this->publicId, 1);
        $summary = $this->service->updateWorkflow($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'blocked', 'normal', ' Waiting on drawings ');

        self::assertSame('blocked', $summary?->toArray()['workflowStatus']);
        self::assertSame('Waiting on drawings', $summary->toArray()['blockedReason']);
        self::assertSame('blocked', $this->column('status'));
        self::assertSame(
            ['from_status' => 'new', 'to_status' => 'blocked', 'from_priority' => 'normal', 'to_priority' => 'normal', 'reason' => 'Waiting on drawings'],
            $this->latest()->detail,
        );
        self::assertSame('workflow_updated', $this->latest()->type);

        // Kept blocked with no new reason: the earlier one stands. Unblocked: gone.
        $kept = $this->service->updateWorkflow($this->manager(), 'mgr@tricab.com', $this->publicId, 1, 'blocked', 'high', '');
        self::assertSame('Waiting on drawings', $kept?->toArray()['blockedReason']);
        $open = $this->service->updateWorkflow($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'in_progress', 'high', '');
        self::assertNull($open?->toArray()['blockedReason']);
        self::assertSame('high', $this->column('priority'));
    }

    public function test_an_unchanged_status_leaves_readiness_and_failure_in_the_status_column(): void
    {
        foreach (['ready_to_sync', 'failed'] as $held) {
            $this->pdo->exec("UPDATE [rfq].[rfq_draft_quotes] SET [status] = N'{$held}', [push_error] = N'boom' WHERE [id] = '{$this->draftId}'");
            $this->service->updateWorkflow($this->manager(), 'mgr@tricab.com', $this->publicId, 1, 'in_progress', 'urgent', '');

            self::assertSame($held, $this->column('status'));
            self::assertSame('boom', $this->column('push_error'));
            self::assertSame('urgent', $this->column('priority'));
        }
    }

    public function test_archive_restore_and_the_sync_and_state_guards(): void
    {
        $archived = $this->service->archive($this->rep(), 'rep@tricab.com', $this->publicId, 1, ' Customer withdrew ');
        self::assertTrue($archived?->toArray()['archived']);
        self::assertSame('Customer withdrew', $this->column('archive_reason'));
        self::assertSame('rep@tricab.com', $this->column('archived_by'));
        self::assertNotNull($this->column('archived_at'));
        self::assertSame(['reason' => 'Customer withdrew'], $this->latest()->detail);

        try {
            $this->service->archive($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'again');
            self::fail('Archiving twice must be refused.');
        } catch (RfqDraftNotEditable $e) {
            self::assertSame(RfqDraftNotEditable::ARCHIVED, $e->reason);
        }

        $restored = $this->service->restore($this->manager(), 'mgr@tricab.com', $this->publicId, 1);
        self::assertFalse($restored?->toArray()['archived']);
        self::assertNull($this->column('archived_at'));
        self::assertNull($this->column('archived_by'));
        self::assertNull($this->column('archive_reason'));
        self::assertSame('restored', $this->latest()->type);

        try {
            $this->service->restore($this->manager(), 'mgr@tricab.com', $this->publicId, 1);
            self::fail('Restoring an RFQ that is not archived must be refused.');
        } catch (RfqDraftNotEditable $e) {
            self::assertSame(RfqDraftNotEditable::NOT_ARCHIVED, $e->reason);
        }

        $this->pdo->exec("UPDATE [rfq].[rfq_draft_quotes] SET [sync_started_at] = N'2026-10-02T01:00:00+00:00' WHERE [id] = '{$this->draftId}'");
        try {
            $this->service->archive($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'during a sync');
            self::fail('Archiving during a sync must be refused.');
        } catch (RfqDraftNotEditable $e) {
            self::assertSame(RfqDraftNotEditable::SYNC_RUNNING, $e->reason);
        }
        self::assertNull($this->column('archived_at'));
    }

    public function test_comments_work_on_an_archived_rfq_and_the_message_is_the_activity_message(): void
    {
        $this->service->archive($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'done elsewhere');
        $this->service->comment($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'Quote went out by phone');

        self::assertSame('commented', $this->latest()->type);
        self::assertSame('Quote went out by phone', $this->latest()->message);
        self::assertSame('Rita Rep', $this->latest()->actorDisplayName);
    }

    public function test_a_workflow_change_of_the_draft_fails_atomically_when_the_policy_refuses(): void
    {
        $this->service->archive($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'gone');
        $before = $this->activityCount();
        try {
            $this->service->updateWorkflow($this->manager(), 'mgr@tricab.com', $this->publicId, 1, 'blocked', 'urgent', 'why');
            self::fail('An archived RFQ keeps its workflow.');
        } catch (\TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted) {
        }
        self::assertSame($before, $this->activityCount());
        self::assertSame('normal', $this->column('priority'));
    }

    public function test_activity_is_newest_first_names_people_and_never_returns_an_email(): void
    {
        $this->service->claim($this->rep(), 'rep@tricab.com', 'Rita Rep', $this->publicId, 1);
        $this->service->comment($this->manager(), 'mgr@tricab.com', $this->publicId, 1, 'First look');
        $this->service->updateWorkflow($this->rep(), 'rep@tricab.com', $this->publicId, 1, 'in_progress', 'normal', '');
        // A content save names the assignee by address in its detail, and a system event has no actor.
        $this->store->saveContent($this->draftId, 1, [], [['raw_text' => 'line 1']], 'in_progress', new DraftActor('mgr@tricab.com', 'admin', null));
        $this->store->recordEvent($this->draftId, 'reader_rows_replaced', 'Read finished', ['to_email' => 'mgr@tricab.com']);

        $events = $this->service->activity($this->manager(), $this->publicId) ?? [];
        self::assertSame(
            ['reader_rows_replaced', 'content_updated', 'workflow_updated', 'commented', 'claimed', 'created'],
            array_map(static fn (RfqAuditEvent $e): string => $e->type, $events),
        );
        self::assertTrue($events[0]->toArray()['system']);
        self::assertNull($events[0]->toArray()['actor']);
        self::assertSame('Max Manager', $events[1]->actorDisplayName);
        $assignee = $events[1]->detail['assignee'] ?? null;
        self::assertIsArray($assignee);
        self::assertSame('Max Manager', $assignee['to'] ?? null);
        self::assertSame('Rita Rep', $assignee['from'] ?? null);
        self::assertSame('Rita Rep', $events[2]->actorDisplayName);

        $encoded = json_encode(array_map(static fn (RfqAuditEvent $e): array => $e->toArray(), $events), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('@', $encoded);
    }

    public function test_the_trail_is_capped_and_an_invisible_rfq_has_none(): void
    {
        for ($i = 0; $i < 3; $i++) {
                $this->service->comment($this->rep(), 'rep@tricab.com', $this->publicId, 1, "note {$i}");
        }
        self::assertCount(2, $this->repository->activity($this->publicId, 2));
        self::assertSame('note 2', $this->repository->activity($this->publicId, 2)[0]->message);
        self::assertNull($this->service->activity(new RfqActor(1, 'x', [], null), $this->publicId));
        self::assertNull($this->service->activity($this->rep(), '99999999-9999-4999-8999-999999999999'));
    }

    public function test_people_lookup_matches_name_or_email_prefix_for_active_users_and_exposes_only_id_and_name(): void
    {
        $names = self::names(...);

        self::assertSame(['Max Manager'], $names($this->service->people($this->manager(), 'manag')));
        self::assertSame(['Max Manager'], $names($this->service->people($this->manager(), 'MGR@')));
        self::assertSame([], $this->service->people($this->manager(), 'tricab.com'), 'a domain is not an email prefix');
        self::assertSame(['Max Manager', 'Rita Rep'], $names($this->service->people($this->manager(), '')), 'active users only');
        self::assertSame([], $this->service->people($this->manager(), '100%_'), 'wildcards are literal');

        $options = array_map(static fn (RfqPerson $p): array => $p->toOption(), $this->service->people($this->manager(), 'rita'));
        self::assertSame([['id' => $this->users['rep']['public'], 'name' => 'Rita Rep']], $options);
        self::assertStringNotContainsString('@', json_encode($options, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<RfqPerson> $people
     *
     * @return list<string>
     */
    private static function names(array $people): array
    {
        return array_map(static fn (RfqPerson $p): string => $p->name, $people);
    }

    private function rep(): RfqActor
    {
        return new RfqActor($this->users['rep']['id'], $this->users['rep']['public'], [
            Module::PERMISSION_DRAFT_READ, Module::PERMISSION_DRAFT_CLAIM, Module::PERMISSION_DRAFT_WORKFLOW,
            Module::PERMISSION_DRAFT_COMMENT, Module::PERMISSION_DRAFT_ARCHIVE,
        ], null);
    }

    private function manager(): RfqActor
    {
        return new RfqActor($this->users['mgr']['id'], $this->users['mgr']['public'], [
            ...$this->rep()->rfqPermissions(), Module::PERMISSION_DRAFT_ASSIGN, Module::PERMISSION_DRAFT_RESTORE,
        ], null);
    }

    /** @return array{id: int, public: string} */
    private function user(string $email, string $display, int $statusId): array
    {
        $status = $this->scalar('SELECT [UserStatusId] FROM [identity].[UserStatuses] WHERE [StatusCode] = ' . $this->pdo->quote(['active', 'pending', 'disabled'][$statusId - 1]));
        $statement = $this->pdo->prepare(
            'INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
             OUTPUT INSERTED.[UserId], LOWER(CONVERT(VARCHAR(36), INSERTED.[PublicId])) VALUES (?, ?, ?, ?)',
        );
        $statement->execute([$email, strtolower($email), $display, $status]);
        $row = $statement->fetch(PDO::FETCH_NUM);
        self::assertIsArray($row);

        self::assertIsNumeric($row[0]);
        self::assertIsString($row[1]);

        return ['id' => (int) $row[0], 'public' => $row[1]];
    }

    private function column(string $name): mixed
    {
        $value = $this->scalar("SELECT [{$name}] FROM [rfq].[rfq_draft_quotes] WHERE [id] = '{$this->draftId}'");

        return $value === false ? null : $value;
    }

    private function activityCount(): int
    {
        $count = $this->scalar("SELECT COUNT(*) FROM [rfq].[rfq_draft_activity] WHERE [draft_id] = '{$this->draftId}'");

        return is_numeric($count) ? (int) $count : 0;
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    private function latest(): RfqAuditEvent
    {
        return $this->repository->activity($this->publicId, 1)[0];
    }
}
