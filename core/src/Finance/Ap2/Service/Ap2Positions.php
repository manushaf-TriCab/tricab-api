<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;

/** What is owed: open AP is the posted, live invoices with a balance, in AUD. */
final class Ap2Positions
{
    /** Posted in Odoo, not excluded, something still owed (the prototype's `open`). */
    public static function isOpen(Ap2Invoice $b): bool
    {
        return $b->isPosted() && !$b->excluded && $b->balance() > 0;
    }

    /**
     * Per supplier: open balance, the part of it past due, and the number of open invoices (AUD).
     *
     * @param list<Ap2Invoice> $invoices
     * @return array<string, array{balance: float, overdue: float, openInvoices: int}>
     */
    public static function bySupplier(array $invoices, Ap2Fx $fx, string $today): array
    {
        $out = [];
        foreach ($invoices as $b) {
            if (!self::isOpen($b)) {
                continue;
            }
            $amount = $fx->toAud($b->balance(), $b->currency);
            $row = $out[$b->supplierId] ?? ['balance' => 0.0, 'overdue' => 0.0, 'openInvoices' => 0];
            $row['balance'] += $amount;
            if (Ap2InvoiceRules::daysOverdue($b, $today) > 0) {
                $row['overdue'] += $amount;
            }
            ++$row['openInvoices'];
            $out[$b->supplierId] = $row;
        }

        return $out;
    }
}
