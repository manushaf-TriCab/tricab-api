<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReminderLogRepository;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

/**
 * Manual Finance action, by design. In legacy TriExpense, missing-receipt chasers
 * were only ever sent when a Finance user clicked send while reconciling — there
 * was no cron for them (unlike the monthly manager digest, which was automatic).
 * Phase 5 keeps that split: this service requires a Finance actor and is reached
 * from the Finance endpoint, never from a scheduler by default.
 *
 * @see MonthlyManagerDigestService for the automatic counterpart
 */
final class PrepareMissingReceiptRemindersService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseNotificationPort $notifications,
        private readonly ExpenseReminderLogRepository $reminderLog,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    public function execute(ExpenseActor $actor): int
    {
        $this->access->requireFinance($actor);

        /** @var array<string, list<\TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry>> $byEmail */
        $byEmail = [];
        foreach ($this->bankFeeds->listAll() as $entry) {
            if (!$entry->matchStatus()->isUnmatched()) {
                continue;
            }
            if ($entry->receiptId() !== null) {
                continue;
            }
            if ($entry->resolution() !== null) {
                continue;
            }
            $email = $entry->cardholderEmail();
            $byEmail[$email][] = $entry;
        }

        $prepared = 0;
        $now = $this->clock->now();
        foreach ($byEmail as $email => $entries) {
            $lines = [];
            foreach ($entries as $entry) {
                $lines[] = sprintf(
                    '%s %s %s %s',
                    $entry->transactionDate()?->format('Y-m-d') ?? '',
                    $entry->merchantName(),
                    $entry->amount()->amount(),
                    $entry->currency()->toString(),
                );
            }
            $summary = sprintf(
                "Missing receipts for %d bank transaction(s):\n%s",
                count($entries),
                implode("\n", $lines),
            );
            $this->notifications->notifyMissingReceiptsDigest([$email], $summary);

            $profile = $this->profiles->findByLegacyEmail($email);
            $this->reminderLog->record(
                $email,
                $profile?->userId(),
                $actor->userId,
                $now,
                null,
                Module::REMINDER_TYPE_MISSING_RECEIPTS,
            );
            $prepared++;
        }

        $this->audit->record(
            $actor->userId,
            'missing_receipt_reminders_prepared',
            null,
            (string) $prepared,
        );

        return $prepared;
    }
}
