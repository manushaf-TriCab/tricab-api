<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;

/** Stage 2/4: no real emails — methods record intent only. */
final class NullExpenseNotificationPort implements ExpenseNotificationPort
{
    /** @var list<array{event: string, detail: mixed}> */
    private array $events = [];

    public function notifySubmitted(ExpenseReceipt $receipt, string $submitMode = 'draft_then_submit'): void
    {
        $this->events[] = ['event' => 'submitted', 'detail' => [$receipt->publicId()->toString(), $submitMode]];
    }

    public function notifyApproved(ExpenseReceipt $receipt): void
    {
        $this->events[] = ['event' => 'approved', 'detail' => $receipt->publicId()->toString()];
    }

    public function notifyRejected(ExpenseReceipt $receipt, string $reason): void
    {
        $this->events[] = ['event' => 'rejected', 'detail' => [$receipt->publicId()->toString(), $reason]];
    }

    public function notifySentBack(ExpenseReceipt $receipt, string $note): void
    {
        $this->events[] = ['event' => 'sent_back', 'detail' => [$receipt->publicId()->toString(), $note]];
    }

    public function notifyChaseBankTx(ExpenseBankFeedEntry $entry): void
    {
        $this->events[] = ['event' => 'chase_bank_tx', 'detail' => $entry->publicId()->toString()];
    }

    public function notifyMissingReceiptsDigest(array $recipientEmails, string $summary): void
    {
        $this->events[] = ['event' => 'missing_receipts_digest', 'detail' => [$recipientEmails, $summary]];
    }

    public function notifyManagerPendingDigest(array $recipientEmails, string $summary): void
    {
        $this->events[] = ['event' => 'manager_pending_digest', 'detail' => [$recipientEmails, $summary]];
    }

    /** @return list<array{event: string, detail: mixed}> */
    public function events(): array
    {
        return $this->events;
    }
}
