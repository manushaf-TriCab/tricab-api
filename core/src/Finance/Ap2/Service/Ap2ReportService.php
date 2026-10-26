<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;

/** `GET /reports/aged`: per-supplier ageing in AUD over posted, open, live invoices. The server owns the bucketing. */
final class Ap2ReportService
{
    public function __construct(
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SettingsStore $settings,
        private readonly ApTime $time,
    ) {
    }

    /**
     * `overdue` is the part of `total` past due; `onHold` says the supplier or one of its open invoices should not be
     * paid yet (a supplier hold, an invoice payment hold, or bank details unverified or changing).
     *
     * @return list<array<string, mixed>> suppliers by name; those with nothing open are left out
     */
    public function aged(): array
    {
        $fx = new Ap2Fx($this->settings->get()->fxRates);
        $today = $this->time->today();
        $rows = [];
        $held = [];
        foreach ($this->invoices->all() as $b) {
            if (!Ap2Positions::isOpen($b)) {
                continue;
            }
            $days = Ap2InvoiceRules::daysOverdue($b, $today);
            $key = $days === 0 ? 'current' : ($days <= 30 ? 'd1_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'd90p')));
            $row = $rows[$b->supplierId] ?? ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90p' => 0.0];
            $row[$key] += $fx->toAud($b->balance(), $b->currency);
            $rows[$b->supplierId] = $row;
            $held[$b->supplierId] = ($held[$b->supplierId] ?? false) || $b->paymentHold;
        }
        $out = [];
        foreach ($this->suppliers->all() as $s) {
            if (!isset($rows[$s->id])) {
                continue;
            }
            $r = $rows[$s->id];
            $total = array_sum($r);
            $out[] = ['supplierId' => $s->id, 'supplierName' => $s->name]
                + array_map(static fn (float $v): float => round($v, 2), $r)
                + [
                    'total' => round($total, 2),
                    'overdue' => round($total - $r['current'], 2),
                    'onHold' => $s->hold || $s->bankChange || !$s->bankVerified || ($held[$s->id] ?? false),
                ];
        }

        return $out;
    }
}
