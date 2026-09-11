<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;

/** Stage 2: no real emails. */
final class NullExpenseNotificationPort implements ExpenseNotificationPort
{
    public function notifySubmitted(ExpenseReceipt $receipt, string $submitMode = 'draft_then_submit'): void
    {
        unset($receipt, $submitMode);
    }
    public function notifyApproved(ExpenseReceipt $receipt): void {}
    public function notifyRejected(ExpenseReceipt $receipt, string $reason): void {}
    public function notifySentBack(ExpenseReceipt $receipt, string $note): void {}
}