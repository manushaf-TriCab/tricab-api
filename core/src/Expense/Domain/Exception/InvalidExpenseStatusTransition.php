<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Exception;

use DomainException;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptStatus;

final class InvalidExpenseStatusTransition extends DomainException
{
    public static function fromTo(ExpenseReceiptStatus $from, ExpenseReceiptStatus $to): self
    {
        return new self(sprintf(
            'Invalid Tri Expense receipt status transition from %s to %s.',
            $from->toString(),
            $to->toString(),
        ));
    }

    public static function notEditable(ExpenseReceiptStatus $status): self
    {
        return new self(sprintf('Receipt in status %s cannot be edited.', $status->toString()));
    }
}