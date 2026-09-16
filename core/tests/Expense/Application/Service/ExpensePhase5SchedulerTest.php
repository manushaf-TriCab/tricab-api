<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAttachmentStore;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReminderLogRepository;
use TriCab\Api\Expense\Persistence\NullExpenseNotificationPort;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\MonthlyManagerDigestService;
use TriCab\Core\Expense\Application\Service\PurgeExpenseReceiptService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Tests\Expense\Doubles\ExpensePhase5Fixtures;
use TriCab\Core\Tests\Expense\Doubles\FailFirstDeleteReceiptRepository;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/** Scheduled Phase 5 jobs: recycle-bin purge and the monthly manager digest. */
final class ExpensePhase5SchedulerTest extends TestCase
{
    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseAttachmentStore $attachments;
    private InMemoryExpenseReminderLogRepository $reminderLog;
    private InMemoryExpenseAuditRecorder $audit;
    private NullExpenseNotificationPort $notifications;

    private UserId $ownerId;
    private UserId $managerId;

    protected function setUp(): void
    {
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->attachments = new InMemoryExpenseAttachmentStore();
        $this->reminderLog = new InMemoryExpenseReminderLogRepository();
        $this->audit = new InMemoryExpenseAuditRecorder();
        $this->notifications = new NullExpenseNotificationPort();

        $this->ownerId = UserId::fromInt(10);
        $this->managerId = UserId::fromInt(11);

        $this->profiles->save(new ExpenseProfile($this->ownerId, 'owner', 'owner@tricab.com', true, false, false, 'mgr', $this->managerId));
        $this->profiles->save(new ExpenseProfile($this->managerId, 'mgr', 'mgr@tricab.com', true, false, false, null, null));
    }

    public function test_purgeDefaultsToADryRunThatDeletesNothing(): void
    {
        $this->seedDeletedReceipt('old', '-40 days', 'old.jpg');

        $summary = $this->purgeService()->purgeExpired();

        self::assertTrue($summary['dryRun']);
        self::assertSame(1, $summary['eligible']);
        self::assertSame(0, $summary['deleted']);
        self::assertSame('would_delete', $summary['receipts'][0]['action']);
        self::assertCount(1, $this->receipts->listAll(true), 'nothing was removed');
        self::assertTrue($this->attachments->objectExists('old.jpg'));
    }

    public function test_purgeRemovesReceiptsPastTheRecycleWindow(): void
    {
        $this->seedDeletedReceipt('old', '-40 days', 'old.jpg');

        $summary = $this->purgeService()->purgeExpired(dryRun: false);

        self::assertSame(1, $summary['deleted']);
        self::assertSame(0, $summary['blocked']);
        self::assertSame('deleted', $summary['receipts'][0]['action']);
        self::assertSame([], $this->receipts->listAll(true));
        self::assertFalse($this->attachments->objectExists('old.jpg'), 'the managed blob went with the row');
    }

    public function test_purgeRetainsRecentlyDeletedAndLiveReceipts(): void
    {
        $this->seedDeletedReceipt('fresh', '-' . (Module::RECYCLE_DAYS - 1) . ' days', 'fresh.jpg');
        $this->receipts->save(ExpensePhase5Fixtures::draftReceipt('live', $this->ownerId));

        $summary = $this->purgeService()->purgeExpired(dryRun: false);

        self::assertSame(0, $summary['eligible']);
        self::assertSame(0, $summary['deleted']);
        self::assertCount(2, $this->receipts->listAll(true));
    }

    public function test_purgeSkipsWordpressOwnedAttachments(): void
    {
        $receipt = ExpensePhase5Fixtures::draftReceipt(
            'wp',
            $this->ownerId,
            ExpenseAttachmentRef::fromLegacyUrl('https://legacy.tricab.com/wp-content/uploads/r.jpg', 'r.jpg'),
        );
        $receipt->softDelete('owner', $this->now()->modify('-90 days'));
        $this->receipts->save($receipt);

        $summary = $this->purgeService()->purgeExpired(dryRun: false);

        self::assertSame(1, $summary['eligible']);
        self::assertSame(0, $summary['deleted']);
        self::assertSame(1, $summary['blocked']);
        self::assertSame('skipped', $summary['receipts'][0]['action']);
        self::assertSame('wordpress_owned_attachment', $summary['receipts'][0]['reason']);
        self::assertCount(1, $this->receipts->listAll(true), 'legacy still serves that file, so the row stays');
    }

    public function test_oneFailingReceiptDoesNotAbortThePurgeBatch(): void
    {
        $this->seedDeletedReceipt('a', '-40 days', 'a.jpg');
        $this->seedDeletedReceipt('b', '-41 days', 'b.jpg');

        $service = new PurgeExpenseReceiptService(
            $this->accessService(),
            new FailFirstDeleteReceiptRepository($this->receipts),
            $this->attachments,
            $this->audit,
            $this->clock(),
        );

        $summary = $service->purgeExpired(dryRun: false);

        self::assertSame(2, $summary['eligible']);
        self::assertSame(1, $summary['deleted'], 'the batch carried on after the failure');
        self::assertSame(1, $summary['blocked']);
        self::assertSame(
            ['failed', 'deleted'],
            array_column($summary['receipts'], 'action'),
        );
    }

    public function test_digestDoesNotRunOutsideTheFirstOfTheMonth(): void
    {
        $this->seedPending('a');

        // 15 September in Melbourne, comfortably not day 1 in either zone.
        $summary = $this->digestService('2026-09-15T02:00:00Z')->execute();

        self::assertFalse($summary['ran']);
        self::assertSame('not_first_of_month', $summary['skippedReason']);
        self::assertSame([], $this->notifications->events());
    }

    public function test_digestEvaluatesDayOneInMelbourneNotUtc(): void
    {
        $this->seedPending('a');

        // 31 Aug 22:00 UTC is already 1 Sep 08:00 in Melbourne, which is when
        // the legacy cron fired.
        $summary = $this->digestService('2026-08-31T22:00:00Z')->execute();

        self::assertTrue($summary['ran']);
        self::assertSame('2026-09-01', $summary['localDate']);
        self::assertSame('2026-09', $summary['periodKey']);
        self::assertSame(1, $summary['sent']);
    }

    public function test_digestMailsEachManagerOncePerMonth(): void
    {
        $this->seedPending('a');
        $this->seedPending('b');

        $first = $this->digestService('2026-09-01T00:00:00Z')->execute();
        self::assertSame(1, $first['managersConsidered'], 'both receipts belong to the same manager');
        self::assertSame(1, $first['sent']);
        self::assertCount(1, $this->notifications->events());
        self::assertStringContainsString('2 pending expense receipt(s)', $this->digestBody());

        // A retried cron run in the same month must not mail the manager twice.
        $second = $this->digestService('2026-09-01T06:00:00Z')->execute();
        self::assertSame(0, $second['sent']);
        self::assertSame(1, $second['alreadySent']);
        self::assertSame('already_sent', $second['recipients'][0]['action']);
        self::assertCount(1, $this->notifications->events(), 'still exactly one digest');

        // The next month is a different key, so the digest goes out again.
        $october = $this->digestService('2026-10-01T00:00:00Z')->execute();
        self::assertSame(1, $october['sent']);
        self::assertCount(2, $this->notifications->events());
    }

    public function test_digestIdempotencyKeyIsManagerPlusMonthPlusType(): void
    {
        $key = MonthlyManagerDigestService::idempotencyKey('Mgr@TriCab.com', '2026-09');

        self::assertSame(
            Module::REMINDER_TYPE_MANAGER_MONTHLY_DIGEST . ':2026-09:mgr@tricab.com',
            $key,
            'case differences must not create a second send',
        );
        self::assertNotSame($key, MonthlyManagerDigestService::idempotencyKey('mgr@tricab.com', '2026-10'));
    }

    public function test_forcedDigestRunsOffScheduleButStaysIdempotent(): void
    {
        $this->seedPending('a');

        $forced = $this->digestService('2026-09-15T02:00:00Z')->execute(force: true);
        self::assertTrue($forced['ran']);
        self::assertSame(1, $forced['sent']);

        $again = $this->digestService('2026-09-16T02:00:00Z')->execute(force: true);
        self::assertSame(0, $again['sent']);
        self::assertSame(1, $again['alreadySent']);
    }

    public function test_digestRecordsAnAuditEntryOnlyWhenAnOperatorIsNamed(): void
    {
        $this->seedPending('a');

        $this->digestService('2026-09-01T00:00:00Z')->execute();
        self::assertSame([], $this->audit->events(), 'an unattended cron run has no human actor');

        $this->digestService('2026-10-01T00:00:00Z')->execute(auditActor: $this->managerId);
        self::assertSame(['manager_monthly_digest_run'], array_column($this->audit->events(), 'action'));
    }

    private function purgeService(): PurgeExpenseReceiptService
    {
        return new PurgeExpenseReceiptService(
            $this->accessService(),
            $this->receipts,
            $this->attachments,
            $this->audit,
            $this->clock(),
        );
    }

    private function digestService(string $nowUtc): MonthlyManagerDigestService
    {
        return new MonthlyManagerDigestService(
            $this->receipts,
            $this->profiles,
            $this->notifications,
            $this->reminderLog,
            $this->audit,
            FixedClock::utc($nowUtc),
        );
    }

    private function accessService(): ExpenseAccessService
    {
        return new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());
    }

    private function clock(): FixedClock
    {
        return FixedClock::utc('2026-09-15T00:00:00Z');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15T00:00:00Z', new DateTimeZone('UTC'));
    }

    private function seedDeletedReceipt(string $suffix, string $deletedAgo, string $blobPath): ExpenseReceipt
    {
        $this->attachments->put($blobPath, 'bytes');
        $receipt = ExpensePhase5Fixtures::draftReceipt(
            $suffix,
            $this->ownerId,
            ExpenseAttachmentRef::azureBlob($blobPath, $blobPath),
        );
        $receipt->softDelete('owner', $this->now()->modify($deletedAgo));

        return $this->receipts->save($receipt);
    }

    private function seedPending(string $suffix): ExpenseReceipt
    {
        return $this->receipts->save(
            ExpensePhase5Fixtures::pendingReceipt($suffix, $this->ownerId, $this->managerId),
        );
    }

    private function digestBody(): string
    {
        $events = $this->notifications->events();

        return json_encode($events[count($events) - 1], JSON_THROW_ON_ERROR);
    }
}
