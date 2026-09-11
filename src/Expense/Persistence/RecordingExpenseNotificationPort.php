<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;

/**
 * Records every notification and forwards it to the wrapped port.
 *
 * Lets a test assert exactly which notifications a flow produced while the real
 * adapter still runs — which is how "a 409 retry must not send a second mail"
 * gets verified without stubbing out the behaviour being tested.
 * {@see NullExpenseNotificationPort} already records on its own; this decorator
 * is for wrapping a port that actually does something.
 */
final class RecordingExpenseNotificationPort implements ExpenseNotificationPort
{
    /** @var list<array{event: string, detail: mixed}> */
    private array $events = [];

    public function __construct(private readonly ExpenseNotificationPort $inner)
    {
    }

    public function notifySubmitted(ExpenseReceipt $receipt, string $submitMode = 'draft_then_submit'): void
    {
        $this->events[] = ['event' => 'submitted', 'detail' => [$receipt->publicId()->toString(), $submitMode]];
        $this->inner->notifySubmitted($receipt, $submitMode);
    }

    public function notifyApproved(ExpenseReceipt $receipt): void
    {
        $this->events[] = ['event' => 'approved', 'detail' => $receipt->publicId()->toString()];
        $this->inner->notifyApproved($receipt);
    }

    public function notifyRejected(ExpenseReceipt $receipt, string $reason): void
    {
        $this->events[] = ['event' => 'rejected', 'detail' => [$receipt->publicId()->toString(), $reason]];
        $this->inner->notifyRejected($receipt, $reason);
    }

    public function notifySentBack(ExpenseReceipt $receipt, string $note): void
    {
        $this->events[] = ['event' => 'sent_back', 'detail' => [$receipt->publicId()->toString(), $note]];
        $this->inner->notifySentBack($receipt, $note);
    }

    public function notifyChaseBankTx(ExpenseBankFeedEntry $entry): void
    {
        $this->events[] = ['event' => 'chase_bank_tx', 'detail' => $entry->publicId()->toString()];
        $this->inner->notifyChaseBankTx($entry);
    }

    public function notifyMissingReceiptsDigest(array $recipientEmails, string $summary): void
    {
        $this->events[] = ['event' => 'missing_receipts_digest', 'detail' => [$recipientEmails, $summary]];
        $this->inner->notifyMissingReceiptsDigest($recipientEmails, $summary);
    }

    public function notifyManagerPendingDigest(array $recipientEmails, string $summary): void
    {
        $this->events[] = ['event' => 'manager_pending_digest', 'detail' => [$recipientEmails, $summary]];
        $this->inner->notifyManagerPendingDigest($recipientEmails, $summary);
    }

    /** @return list<array{event: string, detail: mixed}> */
    public function events(): array
    {
        return $this->events;
    }
}
