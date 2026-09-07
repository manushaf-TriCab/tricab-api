<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;

/**
 * Notifications must not send in unit tests. Production wiring is a later stage.
 */
interface ExpenseNotificationPort
{
    public function notifySubmitted(ExpenseReceipt $receipt): void;

    public function notifyApproved(ExpenseReceipt $receipt): void;

    public function notifyRejected(ExpenseReceipt $receipt, string $reason): void;

    public function notifySentBack(ExpenseReceipt $receipt, string $note): void;
}