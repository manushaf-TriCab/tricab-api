<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;

/**
 * Notifications must not send in unit tests. Production wiring is a later stage.
 */
interface ExpenseNotificationPort
{
    /**
     * @param 'draft_then_submit'|'immediate' $submitMode
     */
    public function notifySubmitted(ExpenseReceipt $receipt, string $submitMode = 'draft_then_submit'): void;

    public function notifyApproved(ExpenseReceipt $receipt): void;

    public function notifyRejected(ExpenseReceipt $receipt, string $reason): void;

    public function notifySentBack(ExpenseReceipt $receipt, string $note): void;

    /** Bank TX chased — records intent only when wired to Null port. */
    public function notifyChaseBankTx(ExpenseBankFeedEntry $entry): void;

    /**
     * @param list<string> $recipientEmails
     */
    public function notifyMissingReceiptsDigest(array $recipientEmails, string $summary): void;

    /**
     * @param list<string> $recipientEmails
     */
    public function notifyManagerPendingDigest(array $recipientEmails, string $summary): void;
}
