<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use Closure;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\DTO\RfqPerson;
use TriCab\Core\Rfq\Application\DTO\RfqRecord;
use TriCab\Core\Rfq\Application\Service\RfqWorkflowService;
use TriCab\Core\Rfq\Contract\RfqPeopleDirectory;
use TriCab\Core\Rfq\Contract\RfqWorkflowStore;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\RfqOwnership;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\WorkflowChange;

/**
 * The workflow panel's service (parity plan E6): who may do what, before any
 * store is touched. The store's locking, revision check and activity rows are
 * proved against SQL Server in `tests/Rfq/RfqWorkflowTest.php`.
 */
final class RfqWorkflowServiceTest extends TestCase
{
    private const PUBLIC_ID = '11111111-1111-4111-8111-111111111111';

    private const REP = 7;
    private const MANAGER = 9;

    /** @var list<string> every store call, with what it was given */
    private array $calls = [];

    /** @var array{status: string, priority: string, archived: bool, revision: int} what the fake store "holds" */
    private array $held = ['status' => 'new', 'priority' => 'normal', 'archived' => false, 'revision' => 4];

    /** @var list<WorkflowChange> */
    private array $changes = [];

    private InMemoryRfqRepository $rfqs;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->changes = [];
        $this->held = ['status' => 'new', 'priority' => 'normal', 'archived' => false, 'revision' => 4];
        $this->rfqs = new InMemoryRfqRepository();
        $this->seed(null, 'new', 'normal', false);
    }

    private function seed(?int $assignee, string $status, string $priority, bool $archived, ?string $blockedReason = null): void
    {
        $base = ReadRfqQueueServiceTest::record(self::PUBLIC_ID, self::REP, null);
        $this->rfqs->withRecord(new RfqRecord(
            $base->publicId,
            $base->reference,
            $base->title,
            $base->customerCode,
            $base->customerName,
            new RfqOwnership(self::REP, $assignee, null, $archived),
            $base->ownerDisplayName,
            $assignee === null ? null : 'Assignee Name',
            WorkflowStatus::fromString($status),
            Priority::fromString($priority),
            null,
            $base->channel,
            $base->extractionState,
            null,
            null,
            3,
            4,
            $base->receivedAtUtc,
            $base->updatedAtUtc,
            $blockedReason,
        ));
        $this->held = ['status' => $status, 'priority' => $priority, 'archived' => $archived, 'revision' => 4];
    }

    private function service(): RfqWorkflowService
    {
        $store = new class ($this) implements RfqWorkflowStore {
            public function __construct(private readonly RfqWorkflowServiceTest $test)
            {
            }

            public function claimForActor(string $draftId, int $expectedRevision, DraftActor $actor, ?string $actorName): bool
            {
                return $this->test->record('claim', $expectedRevision, [$actor->email, $actorName]);
            }

            public function assign(string $draftId, int $expectedRevision, ?string $assigneeEmail, ?string $fromName, ?string $toName, DraftActor $actor): bool
            {
                return $this->test->record('assign', $expectedRevision, [$assigneeEmail, $fromName, $toName]);
            }

            public function setWorkflow(string $draftId, int $expectedRevision, Closure $decide, DraftActor $actor): bool
            {
                $this->test->record('workflow', $expectedRevision, []);
                $held = $this->test->held();
                $this->test->change($decide(WorkflowStatus::fromString($held['status']), Priority::fromString($held['priority']), $held['archived']));

                return true;
            }

            public function archive(string $draftId, int $expectedRevision, string $reason, DraftActor $actor): bool
            {
                return $this->test->record('archive', $expectedRevision, [$reason]);
            }

            public function restore(string $draftId, int $expectedRevision, DraftActor $actor): bool
            {
                return $this->test->record('restore', $expectedRevision, []);
            }

            public function comment(string $draftId, int $expectedRevision, string $message, DraftActor $actor): bool
            {
                return $this->test->record('comment', $expectedRevision, [$message]);
            }
        };
        $people = new class implements RfqPeopleDirectory {
            public function find(string $publicId): ?RfqPerson
            {
                return $publicId === '33333333-3333-4333-8333-333333333333' ? new RfqPerson($publicId, 'Pat Person', 'pat@tricab.com') : null;
            }

            public function search(string $query, int $limit): array
            {
                return [new RfqPerson('33333333-3333-4333-8333-333333333333', 'Pat Person', 'pat@tricab.com')];
            }
        };

        return new RfqWorkflowService($this->rfqs, $store, $people);
    }

    /** @internal for the fake store */
    public function record(string $call, int $revision, array $args): bool
    {
        if ($revision !== $this->held['revision']) {
            throw new RfqRevisionConflict($this->held['revision'], $revision);
        }
        $this->calls[] = $call . ':' . json_encode($args, JSON_THROW_ON_ERROR);

        return true;
    }

    /** @internal for the fake store */
    public function held(): array
    {
        return $this->held;
    }

    /** @internal for the fake store */
    public function change(WorkflowChange $change): void
    {
        $this->changes[] = $change;
    }

    /** @param list<string> $permissions */
    private static function actor(int $id, array $permissions): RfqActor
    {
        return new RfqActor($id, "public-{$id}", $permissions, null);
    }

    private static function rep(): RfqActor
    {
        return self::actor(self::REP, [Module::PERMISSION_DRAFT_READ, Module::PERMISSION_DRAFT_CLAIM, Module::PERMISSION_DRAFT_WORKFLOW, Module::PERMISSION_DRAFT_COMMENT, Module::PERMISSION_DRAFT_ARCHIVE]);
    }

    private static function manager(): RfqActor
    {
        return self::actor(self::MANAGER, [...self::rep()->rfqPermissions(), Module::PERMISSION_DRAFT_ASSIGN, Module::PERMISSION_DRAFT_RESTORE]);
    }

    public function test_an_unseen_or_unknown_rfq_is_null_for_every_write(): void
    {
        $service = $this->service();
        $nobody = self::actor(1, []);

        self::assertNull($service->claim($nobody, 'x@tricab.com', 'X', self::PUBLIC_ID, 4));
        self::assertNull($service->comment($nobody, 'x@tricab.com', self::PUBLIC_ID, 4, 'hello'));
        self::assertNull($service->activity($nobody, self::PUBLIC_ID));
        self::assertNull($service->claim(self::rep(), 'rep@tricab.com', 'Rep', '22222222-2222-4222-8222-222222222222', 4));
        self::assertSame([], $this->calls);
    }

    public function test_claim_takes_an_unassigned_rfq_and_answers_the_fresh_summary(): void
    {
        $summary = $this->service()->claim(self::rep(), 'Rep@TriCab.com', 'Rep Person', self::PUBLIC_ID, 4);

        self::assertNotNull($summary);
        self::assertSame(self::PUBLIC_ID, $summary->toArray()['publicId']);
        self::assertSame(['claim:["rep@tricab.com","Rep Person"]'], $this->calls);
    }

    public function test_claim_is_refused_when_assigned_archived_or_not_permitted(): void
    {
        $this->seed(self::MANAGER, 'new', 'normal', false);
        $this->expectException(RfqActionForbidden::class);
        try {
            $this->service()->claim(self::rep(), 'rep@tricab.com', 'Rep', self::PUBLIC_ID, 4);
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function test_claim_is_refused_on_an_archived_rfq(): void
    {
        $this->seed(null, 'new', 'normal', true);
        $this->expectException(RfqActionForbidden::class);
        $this->service()->claim(self::rep(), 'rep@tricab.com', 'Rep', self::PUBLIC_ID, 4);
    }

    public function test_a_stale_revision_is_a_conflict_and_writes_nothing(): void
    {
        try {
            $this->service()->claim(self::rep(), 'rep@tricab.com', 'Rep', self::PUBLIC_ID, 3);
            self::fail('Expected a conflict.');
        } catch (RfqRevisionConflict $conflict) {
            self::assertSame(4, $conflict->currentRevision);
            self::assertSame([], $this->calls);
        }
    }

    public function test_only_a_manager_assigns_and_the_id_must_be_an_active_user(): void
    {
        $service = $this->service();
        try {
            $service->assign(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, '33333333-3333-4333-8333-333333333333');
            self::fail('A rep must not assign.');
        } catch (RfqActionForbidden) {
        }
        try {
            $service->assign(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, '44444444-4444-4444-8444-444444444444');
            self::fail('An unknown id must be refused.');
        } catch (InvalidRfqValue) {
        }
        self::assertSame([], $this->calls);

        $service->assign(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, '33333333-3333-4333-8333-333333333333');
        $service->assign(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, null);
        self::assertSame(['assign:["pat@tricab.com",null,"Pat Person"]', 'assign:[null,null,null]'], $this->calls);
    }

    public function test_an_assignee_may_start_or_block_but_not_reset_work_or_change_priority(): void
    {
        $this->seed(self::REP, 'new', 'normal', false);
        $service = $this->service();

        $service->updateWorkflow(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, 'in_progress', 'normal', '');
        self::assertSame('in_progress', $this->changes[0]->storeStatus);

        $this->seed(self::REP, 'in_progress', 'normal', false);
        try {
            $service->updateWorkflow(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, 'new', 'normal', '');
            self::fail('An assignee must not return work to new.');
        } catch (RfqTransitionNotPermitted $e) {
            self::assertSame(RfqTransitionNotPermitted::REASON_ACTOR, $e->reason);
        }
        try {
            $service->updateWorkflow(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, 'in_progress', 'urgent', '');
            self::fail('An assignee must not change priority.');
        } catch (RfqActionForbidden) {
        }
        self::assertCount(1, $this->changes);
    }

    public function test_an_unchanged_status_is_not_rewritten_and_an_unchanged_priority_is_fine_for_an_assignee(): void
    {
        // ready_to_sync and failed read as in_progress: saving in_progress must not clobber them.
        $this->seed(self::REP, 'in_progress', 'high', false);
        $this->service()->updateWorkflow(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, 'in_progress', 'high', '');

        self::assertNull($this->changes[0]->storeStatus);
        self::assertSame('high', $this->changes[0]->priority->toString());
        self::assertSame(
            ['from_status' => 'in_progress', 'to_status' => 'in_progress', 'from_priority' => 'high', 'to_priority' => 'high', 'reason' => ''],
            $this->changes[0]->detail,
        );
    }

    public function test_a_manager_changes_priority_and_returns_work_to_new(): void
    {
        $this->seed(self::REP, 'in_progress', 'normal', false);
        $this->service()->updateWorkflow(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, 'new', 'urgent', '  ');

        self::assertSame('new', $this->changes[0]->storeStatus);
        self::assertSame('urgent', $this->changes[0]->priority->toString());
    }

    public function test_blocking_needs_a_reason_and_it_is_trimmed(): void
    {
        $this->seed(self::REP, 'in_progress', 'normal', false);
        $service = $this->service();
        try {
            $service->updateWorkflow(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, 'blocked', 'normal', '   ');
            self::fail('A blocked RFQ needs a reason.');
        } catch (RfqTransitionNotPermitted $e) {
            self::assertSame(RfqTransitionNotPermitted::REASON_INPUT, $e->reason);
        }
        $service->updateWorkflow(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, 'blocked', 'normal', '  Waiting on the customer ');
        self::assertSame('blocked', $this->changes[0]->storeStatus);
        self::assertSame('Waiting on the customer', $this->changes[0]->detail['reason']);
    }

    public function test_completed_is_never_set_by_hand_and_an_archived_workflow_does_not_move(): void
    {
        $this->seed(self::REP, 'in_progress', 'normal', false);
        $service = $this->service();
        try {
            $service->updateWorkflow(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, 'completed', 'normal', '');
            self::fail('Nobody sets completed by hand.');
        } catch (RfqTransitionNotPermitted $e) {
            self::assertSame(RfqTransitionNotPermitted::REASON_ACTOR, $e->reason);
        }

        $this->seed(self::REP, 'completed', 'normal', false);
        try {
            $service->updateWorkflow(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, 'in_progress', 'normal', '');
            self::fail('Completed work does not reopen by hand.');
        } catch (RfqTransitionNotPermitted $e) {
            self::assertSame(RfqTransitionNotPermitted::REASON_STATE, $e->reason);
        }

        $this->seed(self::REP, 'in_progress', 'normal', true);
        try {
            $service->updateWorkflow(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, 'in_progress', 'normal', '');
            self::fail('An archived RFQ keeps its workflow.');
        } catch (RfqTransitionNotPermitted $e) {
            self::assertSame(RfqTransitionNotPermitted::REASON_STATE, $e->reason);
        }
        self::assertSame([], $this->changes);
    }

    public function test_a_status_outside_the_vocabulary_or_a_bad_priority_is_invalid(): void
    {
        foreach ([['synced', 'normal'], ['new', 'whenever']] as [$status, $priority]) {
            try {
                $this->service()->updateWorkflow(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, $status, $priority, '');
                self::fail('Expected InvalidRfqValue.');
            } catch (InvalidRfqValue) {
            }
        }
        $this->expectException(InvalidRfqValue::class);
        $this->service()->updateWorkflow(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4, 'blocked', 'normal', str_repeat('x', 501));
    }

    public function test_archive_needs_a_reason_and_restore_its_own_permission(): void
    {
        $service = $this->service();
        try {
            $service->archive(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, '   ');
            self::fail('A reason is required.');
        } catch (InvalidRfqValue) {
        }
        try {
            $service->archive(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, str_repeat('é', 501));
            self::fail('A reason over 500 characters is refused.');
        } catch (InvalidRfqValue) {
        }
        try {
            $service->restore(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4);
            self::fail('Restore is its own permission.');
        } catch (RfqActionForbidden) {
        }
        $service->archive(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, '  Customer withdrew ');
        $service->restore(self::manager(), 'm@tricab.com', self::PUBLIC_ID, 4);

        self::assertSame(['archive:["Customer withdrew"]', 'restore:[]'], $this->calls);
    }

    public function test_comments_are_trimmed_bounded_and_allowed_on_an_archived_rfq(): void
    {
        $this->seed(null, 'new', 'normal', true);
        $service = $this->service();
        foreach (['   ', str_repeat('x', 2_001)] as $bad) {
            try {
                $service->comment(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, $bad);
                self::fail('Expected InvalidRfqValue.');
            } catch (InvalidRfqValue) {
            }
        }
        $service->comment(self::rep(), 'rep@tricab.com', self::PUBLIC_ID, 4, '  Rang the customer  ');
        self::assertSame(['comment:["Rang the customer"]'], $this->calls);

        $this->expectException(RfqActionForbidden::class);
        $service->comment(self::actor(1, [Module::PERMISSION_DRAFT_READ]), 'x@tricab.com', self::PUBLIC_ID, 4, 'hi');
    }

    public function test_the_owner_picker_is_for_managers_and_carries_no_email(): void
    {
        $people = $this->service()->people(self::manager(), 'pat');
        self::assertSame([['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Pat Person']], array_map(static fn (RfqPerson $p): array => $p->toOption(), $people));

        $this->expectException(RfqActionForbidden::class);
        $this->service()->people(self::rep(), 'pat');
    }

    public function test_the_summary_carries_the_blocked_reason_only_while_blocked(): void
    {
        $this->seed(self::REP, 'blocked', 'normal', false, 'Waiting on drawings');
        $blocked = $this->service()->comment(self::rep(), 'r@tricab.com', self::PUBLIC_ID, 4, 'ok');
        self::assertSame('Waiting on drawings', $blocked?->toArray()['blockedReason']);

        $this->seed(self::REP, 'in_progress', 'normal', false, 'Waiting on drawings');
        $open = $this->service()->comment(self::rep(), 'r@tricab.com', self::PUBLIC_ID, 4, 'ok');
        self::assertNull($open?->toArray()['blockedReason']);
    }
}
