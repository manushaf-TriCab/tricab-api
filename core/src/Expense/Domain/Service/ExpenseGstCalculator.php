<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Service;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Module;

final class ExpenseGstCalculator
{
    public static function normalizeType(string $gstType): string
    {
        $normalized = strtolower(trim($gstType));
        if ($normalized === '') {
            return Module::GST_UNKNOWN;
        }
        if (!in_array($normalized, [Module::GST_INCLUSIVE, Module::GST_EXCLUSIVE, Module::GST_UNKNOWN], true)) {
            throw InvalidExpenseValue::for('gstType', $gstType);
        }

        return $normalized;
    }

    public static function inclusiveFromTotal(ExpenseMoney $total): ExpenseMoney
    {
        $raw = bcdiv($total->amount(), '11', 6);
        $scaled = bcmul($raw, '100', 6);
        $cents = bcadd($scaled, '0.5', 0);

        return ExpenseMoney::fromString(bcdiv($cents, '100', 2));
    }

    public static function assertPossible(ExpenseMoney $total, ExpenseMoney $gst): void
    {
        if (bccomp($gst->amount(), $total->amount(), 6) === 1) {
            throw InvalidExpenseValue::for('gstAmount', $gst->amount());
        }
    }
}
