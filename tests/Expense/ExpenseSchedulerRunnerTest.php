<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Expense;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAttachmentStore;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReminderLogRepository;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Api\Expense\Scheduler\ExpenseSchedulerRunner;
use TriCab\Api\Expense\Scheduler\FileExpenseSchedulerLock;
use TriCab\Api\Expense\Scheduler\FileExpenseSchedulerRunStore;
use TriCab\Api\Expense\Scheduler\InMemoryExpenseSchedulerLock;
use TriCab\Api\Expense\Scheduler\InMemoryExpenseSchedulerRunStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\MonthlyManagerDigestService;
use TriCab\Core\Expense\Application\Service\PurgeExpenseReceiptService;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptStatus;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Tests\Expense\Doubles\ExpensePhase5Fixtures;
use TriCab\Core\Tests\Expense\Doubles\FailFirstDeleteReceiptRepository;

/** Scheduler dispatcher: fake clock, in-memory repositories, no real mail, no database. */
final class ExpenseSchedulerRunnerTest extends TestCase
{
    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseAttachmentStore $attachments;
    private InMemoryExpenseReminderLogRepository $reminderLog;
    private SelectiveFailNotifier $notifier;
    private InMemoryExpenseSchedulerLock $lock;
    private InMemoryExpenseSchedulerRunStore $store;
    /** @var list<array<string, mixed>> */
    private array $logs = [];
    private bool $failingDeletes = false;

    protected function setUp(): void
    {
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->attachments = new InMemoryExpenseAttachmentStore();
        $this->reminderLog = new InMemoryExpenseReminderLogRepository();
        $this->notifier = new SelectiveFailNotifier();
        $this->lock = new InMemoryExpenseSchedulerLock();
        $this->store = new InMemoryExpenseSchedulerRunStore();
        $this->logs = [];

        $owner = UserId::fromInt(10);
        $this->profiles->save(new ExpenseProfile($owner, 'owner', 'owner@tricab.com', true, false, false, 'm1', UserId::fromInt(11)));
        $this->profiles->save(new ExpenseProfile(UserId::fromInt(11), 'm1', 'm1@tricab.com', true, false, false, null, null));
        $this->profiles->save(new ExpenseProfile(UserId::fromInt(12), 'm2', 'm2@tricab.com', true, false, false, null, null));
        $this->receipts->save(ExpensePhase5Fixtures::pendingReceipt('a', $owner, UserId::fromInt(11)));
        $this->receipts->save(ExpensePhase5Fixtures::pendingReceipt('b', $owner, UserId::fromInt(12)));
    }

    public function test_digestSentOncePerManagerPerMonthEvenWhenRunDailyOrTwice(): void
    {
        foreach (['2026-09-01T00:00:00Z', '2026-09-01T00:05:00Z', '2026-09-02T00:00:00Z', '2026-09-03T00:00:00Z'] as $t) {
            $out = $this->runner($t)->run(['digest']);
            self::assertSame(0, $out['exitCode']);
        }
        self::assertCount(2, $this->notifier->sent, 'two managers, one mail each, despite four runs');
        self::assertSame(2, $this->store->last('digest')['alreadySent']);
    }

    public function test_dayOneIsMelbourneNotUtc(): void
    {
        // 31 Aug 14:30 UTC = 1 Sep 00:30 AEST/AEDT? (Sep 1 is AEST, +10): due.
        $due = $this->runner('2026-08-31T14:30:00Z')->run(['digest'], false, 1);
        self::assertTrue($due['results']['digest']['ran']);
        self::assertSame('2026-09-01', $due['results']['digest']['localDate']);

        // 31 Aug 13:30 UTC = 31 Aug 23:30 Melbourne: not day 1 yet.
        $this->setUp();
        $early = $this->runner('2026-08-31T13:30:00Z')->run(['digest'], false, 1);
        self::assertFalse($early['results']['digest']['ran']);
        self::assertSame([], $this->notifier->sent);
    }

    public function test_dstBoundaryOctoberFirstIsAedt(): void
    {
        // DST starts 4 Oct 2026; 1 Oct is still AEST (+10): 30 Sep 14:00Z is 1 Oct 00:00 local.
        $out = $this->runner('2026-09-30T14:00:00Z')->run(['digest'], false, 1);
        self::assertSame('2026-10-01', $out['results']['digest']['localDate']);
        self::assertTrue($out['results']['digest']['ran']);
        // 1 Nov is AEDT (+11): 31 Oct 13:00Z is 1 Nov 00:00 local.
        $this->setUp();
        $nov = $this->runner('2026-10-31T13:00:00Z')->run(['digest'], false, 1);
        self::assertSame('2026-11-01', $nov['results']['digest']['localDate']);
    }

    public function test_missedDayOneIsCaughtUpWithinWindowOnly(): void
    {
        $in = $this->runner('2026-09-05T02:00:00Z')->run(['digest'], false, 7);
        self::assertSame(2, $in['results']['digest']['sent'], 'cron missed day 1; caught up on day 5');

        $this->setUp();
        $out = $this->runner('2026-09-20T02:00:00Z')->run(['digest'], false, 7);
        self::assertFalse($out['results']['digest']['ran'], 'a mid-month cutover must not surprise-mail');
        self::assertSame([], $this->notifier->sent);
    }

    public function test_oneManagersFailureDoesNotStopOthersAndIsRetriedNextRun(): void
    {
        $this->notifier->failFor = 'm1@tricab.com';
        $first = $this->runner('2026-09-01T00:00:00Z')->run(['digest']);
        self::assertSame(1, $first['exitCode'], 'non-zero so the WebJob shows failed');
        self::assertSame(1, $first['results']['digest']['sent']);
        self::assertSame(1, $first['results']['digest']['failed']);
        self::assertSame(['m2@tricab.com'], $this->notifier->sent);

        $this->notifier->failFor = null;
        $second = $this->runner('2026-09-02T00:00:00Z')->run(['digest']);
        self::assertSame(0, $second['exitCode']);
        self::assertSame(1, $second['results']['digest']['sent']);
        self::assertSame(1, $second['results']['digest']['alreadySent']);
        self::assertEqualsCanonicalizing(['m1@tricab.com', 'm2@tricab.com'], $this->notifier->sent);
    }

    public function test_concurrentInstanceIsRefusedWithExit5AndDoesNothing(): void
    {
        $release = $this->lock->acquire('digest');
        self::assertNotNull($release);
        $out = $this->runner('2026-09-01T00:00:00Z')->run(['digest']);
        self::assertSame(ExpenseSchedulerRunner::EXIT_LOCKED, $out['exitCode']);
        self::assertSame('locked', $out['results']['digest']['status']);
        self::assertSame([], $this->notifier->sent);
        self::assertNull($this->store->last('digest'), 'the holder owns the health record');

        $release();
        self::assertSame(0, $this->runner('2026-09-01T00:00:00Z')->run(['digest'])['exitCode']);
    }

    public function test_fileLockExcludesASecondHolderAndReleases(): void
    {
        $dir = sys_get_temp_dir() . '/expense-sched-' . bin2hex(random_bytes(4));
        $a = new FileExpenseSchedulerLock($dir);
        $b = new FileExpenseSchedulerLock($dir);
        $release = $a->acquire('digest');
        self::assertNotNull($release);
        self::assertNull($b->acquire('digest'));
        self::assertNotNull($b->acquire('purge'), 'locks are per job');
        $release();
        $again = $b->acquire('digest');
        self::assertNotNull($again);
        $again();
    }

    public function test_purgeDefaultsToDryRunAndApplyDeletesAndRerunIsSafe(): void
    {
        $this->seedExpired('x', '-40 days', 'x.jpg');

        $dry = $this->runner('2026-09-15T00:00:00Z')->run(['purge']);
        self::assertTrue($dry['results']['purge']['dryRun']);
        self::assertSame(0, $dry['results']['purge']['deleted']);
        self::assertTrue($this->attachments->objectExists('x.jpg'));

        $applied = $this->runner('2026-09-15T00:00:00Z')->run(['purge'], true);
        self::assertSame(1, $applied['results']['purge']['deleted']);
        self::assertFalse($this->attachments->objectExists('x.jpg'));

        $rerun = $this->runner('2026-09-15T01:00:00Z')->run(['purge'], true);
        self::assertSame(0, $rerun['exitCode']);
        self::assertSame(0, $rerun['results']['purge']['eligible']);
        self::assertSame(0, $rerun['results']['purge']['deleted']);
    }

    public function test_purgeFailureGivesNonZeroExitButOtherJobStillRuns(): void
    {
        $this->seedExpired('y', '-40 days', 'y.jpg');
        $this->failingDeletes = true;
        $out = $this->runner('2026-09-15T00:00:00Z')->run(['purge', 'digest'], true);
        self::assertSame(1, $out['exitCode']);
        self::assertSame('failed', $out['results']['purge']['status']);
        self::assertSame(1, $out['results']['purge']['failed']);
        self::assertSame('ok', $out['results']['digest']['status'], 'failure isolation between jobs');
    }

    public function test_structuredJsonLogAndLastRunRecordWithoutRecipientsOrBodies(): void
    {
        $this->runner('2026-09-01T00:00:00Z')->run(['digest']);
        self::assertCount(1, $this->logs);
        $line = json_decode(json_encode($this->logs[0], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('expense_scheduler', $line['event']);
        self::assertSame('digest', $line['job']);
        self::assertSame('ok', $line['status']);
        self::assertSame('2026-09', $line['periodKey']);
        $last = $this->store->last('digest');
        self::assertSame('ok', $last['status']);
        self::assertStringNotContainsString('tricab.com', json_encode([$line, $last], JSON_THROW_ON_ERROR));
    }

    public function test_fileRunStoreRoundTrips(): void
    {
        $dir = sys_get_temp_dir() . '/expense-sched-' . bin2hex(random_bytes(4));
        $store = new FileExpenseSchedulerRunStore($dir);
        self::assertNull($store->last('digest'));
        $store->record('digest', ['status' => 'ok', 'sent' => 2]);
        self::assertSame(['status' => 'ok', 'sent' => 2], $store->last('digest'));
    }

    public function test_statusSetIsStillDraftPendingApprovedRejected(): void
    {
        self::assertSame(
            ['draft', 'pending', 'approved', 'rejected'],
            [
                ExpenseReceiptStatus::DRAFT,
                ExpenseReceiptStatus::PENDING,
                ExpenseReceiptStatus::APPROVED,
                ExpenseReceiptStatus::REJECTED,
            ],
        );
        self::assertTrue(ExpenseReceiptStatus::draft()->isEditableByOwner());
        self::assertTrue(ExpenseReceiptStatus::rejected()->isEditableByOwner());
        self::assertFalse(ExpenseReceiptStatus::pending()->isEditableByOwner());
        self::assertFalse(ExpenseReceiptStatus::approved()->isEditableByOwner());
    }

    private function runner(string $nowUtc): ExpenseSchedulerRunner
    {
        $clock = FixedClock::utc($nowUtc);
        $audit = new InMemoryExpenseAuditRecorder();

        return new ExpenseSchedulerRunner(
            new MonthlyManagerDigestService($this->receipts, $this->profiles, $this->notifier, $this->reminderLog, $audit, $clock),
            new PurgeExpenseReceiptService(
                new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver()),
                $this->failingDeletes ? new FailFirstDeleteReceiptRepository($this->receipts) : $this->receipts,
                $this->attachments,
                $audit,
                $clock,
            ),
            $this->lock,
            $this->store,
            $clock,
            function (array $line): void {
                $this->logs[] = $line;
            },
        );
    }

    private function seedExpired(string $suffix, string $ago, string $blob): void
    {
        $this->attachments->put($blob, 'bytes');
        $receipt = ExpensePhase5Fixtures::draftReceipt(
            $suffix,
            UserId::fromInt(10),
            ExpenseAttachmentRef::azureBlob($blob, $blob),
        );
        $receipt->softDelete('owner', (new \DateTimeImmutable('2026-09-15T00:00:00Z'))->modify($ago));
        $this->receipts->save($receipt);
    }
}

final class SelectiveFailNotifier implements ExpenseNotificationPort
{
    /** @var list<string> */
    public array $sent = [];
    public ?string $failFor = null;

    public function notifyManagerPendingDigest(array $recipientEmails, string $summary): void
    {
        if ($this->failFor !== null && in_array($this->failFor, $recipientEmails, true)) {
            throw new \RuntimeException('graph down');
        }
        array_push($this->sent, ...$recipientEmails);
    }

    public function notifySubmitted(ExpenseReceipt $receipt, string $submitMode = 'draft_then_submit'): void {}
    public function notifyApproved(ExpenseReceipt $receipt): void {}
    public function notifyRejected(ExpenseReceipt $receipt, string $reason): void {}
    public function notifySentBack(ExpenseReceipt $receipt, string $note): void {}
    public function notifyChaseBankTx(ExpenseBankFeedEntry $entry): void {}
    public function notifyMissingReceiptsDigest(array $recipientEmails, string $summary): void {}
}
