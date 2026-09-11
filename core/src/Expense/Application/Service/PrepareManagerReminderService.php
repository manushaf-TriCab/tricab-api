<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\ExpenseReminderLogRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Shared\Time\Clock;

final class PrepareManagerReminderService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
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

        /** @var array<int, list<\TriCab\Core\Expense\Domain\Entity\ExpenseReceipt>> $byManager */
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

        $prepared = 0;
        $now = $this->clock->now();
        foreach ($byManager as $managerUserId => $pending) {
            $managerProfile = $this->profiles->findByUserId(UserId::fromInt($managerUserId));
            $email = $managerProfile?->legacyEmail();
            if ($email === null || $email === '') {
                continue;
            }

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
            $summary = sprintf(
                "You have %d pending expense receipt(s) to approve:\n%s",
                count($pending),
                implode("\n", $lines),
            );
            $this->notifications->notifyManagerPendingDigest([$email], $summary);
            $this->reminderLog->record(
                $email,
                $managerProfile->userId(),
                $actor->userId,
                $now,
            );
            $prepared++;
        }

        $this->audit->record(
            $actor->userId,
            'manager_reminders_prepared',
            null,
            (string) $prepared,
        );

        return $prepared;
    }
}
