<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\ExpenseReminderLogRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Shared\Time\Clock;

/**
 * The automatic half of the legacy reminder pair: a digest of still-pending
 * receipts, mailed to each manager on the first of the month.
 *
 * Legacy cron ran in Melbourne local time, so "day 1" is evaluated in
 * Australia/Melbourne, not UTC — otherwise the job would fire on the last day
 * of the previous month for ten months of the year.
 *
 * Missing-receipt reminders are deliberately not here: those were always a
 * manual Finance action. See {@see PrepareMissingReceiptRemindersService}.
 */
final class MonthlyManagerDigestService
{
    public function __construct(
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseNotificationPort $notifications,
        private readonly ExpenseReminderLogRepository $reminderLog,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param bool $force run outside day 1 (operator override for a missed cron)
     * @param ?UserId $auditActor scheduler runs have no human actor; when the
     *        operator supplies their own id the run is written to the audit log
     * @return array{
     *     ran: bool,
     *     skippedReason: ?string,
     *     periodKey: string,
     *     localDate: string,
     *     managersConsidered: int,
     *     sent: int,
     *     alreadySent: int,
     *     skipped: int,
     *     recipients: list<array{email: string, pending: int, action: string}>
     * }
     */
    public function execute(bool $force = false, ?UserId $auditActor = null): array
    {
        $nowUtc = $this->clock->now();
        $local = $nowUtc->setTimezone(new DateTimeZone(Module::SCHEDULER_TIMEZONE));
        $periodKey = $local->format('Y-m');

        if (!$force && (int) $local->format('j') !== 1) {
            return [
                'ran' => false,
                'skippedReason' => 'not_first_of_month',
                'periodKey' => $periodKey,
                'localDate' => $local->format('Y-m-d'),
                'managersConsidered' => 0,
                'sent' => 0,
                'alreadySent' => 0,
                'skipped' => 0,
                'recipients' => [],
            ];
        }

        $byManager = $this->pendingByManager();

        $sent = 0;
        $alreadySent = 0;
        $skipped = 0;
        $recipients = [];

        foreach ($byManager as $managerUserId => $pending) {
            $managerProfile = $this->profiles->findByUserId(UserId::fromInt($managerUserId));
            $email = $managerProfile?->legacyEmail();
            if ($managerProfile === null || $email === null || $email === '') {
                $skipped++;
                continue;
            }

            $key = self::idempotencyKey($email, $periodKey);
            if ($this->reminderLog->existsByIdempotencyKey($key)) {
                $alreadySent++;
                $recipients[] = ['email' => $email, 'pending' => count($pending), 'action' => 'already_sent'];
                continue;
            }

            $this->notifications->notifyManagerPendingDigest(
                [$email],
                self::summary($pending, $periodKey),
            );
            $this->reminderLog->record(
                $email,
                $managerProfile->userId(),
                null,
                $nowUtc,
                'scheduler:monthly-manager-digest',
                Module::REMINDER_TYPE_MANAGER_MONTHLY_DIGEST,
                $key,
            );
            $sent++;
            $recipients[] = ['email' => $email, 'pending' => count($pending), 'action' => 'sent'];
        }

        if ($auditActor !== null) {
            $this->audit->record(
                $auditActor,
                'manager_monthly_digest_run',
                null,
                sprintf('%s sent=%d alreadySent=%d skipped=%d', $periodKey, $sent, $alreadySent, $skipped),
            );
        }

        return [
            'ran' => true,
            'skippedReason' => null,
            'periodKey' => $periodKey,
            'localDate' => $local->format('Y-m-d'),
            'managersConsidered' => count($byManager),
            'sent' => $sent,
            'alreadySent' => $alreadySent,
            'skipped' => $skipped,
            'recipients' => $recipients,
        ];
    }

    /** Manager + month + reminder type: one digest per manager per month. */
    public static function idempotencyKey(string $managerEmail, string $periodKey): string
    {
        return sprintf(
            '%s:%s:%s',
            Module::REMINDER_TYPE_MANAGER_MONTHLY_DIGEST,
            $periodKey,
            strtolower(trim($managerEmail)),
        );
    }

    /**
     * @return array<int, list<ExpenseReceipt>>
     */
    private function pendingByManager(): array
    {
        $byManager = [];
        foreach ($this->receipts->listAll(false) as $receipt) {
            if (!$receipt->status()->isPending()) {
                continue;
            }
            $managerId = $receipt->managerUserId();
            if ($managerId === null) {
                continue;
            }
            $byManager[$managerId->toInt()][] = $receipt;
        }

        return $byManager;
    }

    /**
     * @param list<ExpenseReceipt> $pending
     */
    private static function summary(array $pending, string $periodKey): string
    {
        $lines = [];
        foreach ($pending as $receipt) {
            $lines[] = sprintf(
                '%s %s %s %s',
                $receipt->publicId()->toString(),
                $receipt->vendorName(),
                $receipt->totalAmount()->amount(),
                $receipt->currency()->toString(),
            );
        }

        return sprintf(
            "Monthly digest %s — you have %d pending expense receipt(s) to approve:\n%s",
            $periodKey,
            count($pending),
            implode("\n", $lines),
        );
    }
}
