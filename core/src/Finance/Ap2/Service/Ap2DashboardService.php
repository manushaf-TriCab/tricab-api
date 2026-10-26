<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/** `GET /dashboard`: the prototype's dashboard, computed on the server. Totals are in AUD ({@see Ap2Fx}). */
final class Ap2DashboardService
{
    private const TOP_SUPPLIERS = 6;

    public function __construct(
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2Assessor $assessor,
        private readonly ApTime $time,
    ) {
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $today = $this->time->today();
        $fx = new Ap2Fx($this->settings->get()->fxRates);
        $suppliers = [];
        foreach ($this->suppliers->all() as $s) {
            $suppliers[$s->id] = $s;
        }
        $all = $this->invoices->all();
        $assessments = $this->assessor->many($all, $suppliers, $today);
        $horizon = Ap2Queues::plusDays($today, 5);

        $counts = array_fill_keys(['needsReview', 'awaitingMatching', 'approval', 'due5d', 'paymentHold', 'syncFailed'], 0);
        $keys = ['needsReview' => 'Needs review', 'awaitingMatching' => 'Awaiting matching', 'approval' => 'Approval', 'due5d' => 'Due in 5 days', 'paymentHold' => 'Payment hold', 'syncFailed' => 'Sync failed'];
        $ageing = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90p' => 0.0];
        $openAp = 0.0;
        $overdue = 0.0;
        $spend = [];
        $forecast = [];
        $poSeen = [];
        foreach ($all as $b) {
            $a = $assessments[$b->id];
            $s = $suppliers[$b->supplierId] ?? null;
            foreach ($keys as $field => $queue) {
                if (Ap2Queues::matches($queue, $b, $s, $a, $today)) {
                    ++$counts[$field];
                }
            }
            if (!$b->excluded && $b->po !== '') {
                $poSeen[$b->po] = true;
            }
            if (Ap2Positions::isOpen($b)) {
                $amount = $fx->toAud($b->balance(), $b->currency);
                $openAp += $amount;
                $days = $a->daysOverdue;
                $overdue += $days > 0 ? $amount : 0.0;
                $bucket = $days === 0 ? 'current' : ($days <= 30 ? 'd1_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'd90p')));
                $ageing[$bucket] += $amount;
            }
            if (!$b->excluded && $a->duplicateOf === []) {
                $spend[$b->supplierId] = ($spend[$b->supplierId] ?? 0.0) + $fx->toAud($b->total - $b->credit, $b->currency);
                if ($b->balance() > 0) {
                    $forecast[] = [
                        'id' => $b->id,
                        'source' => $b->number !== '' ? $b->number : ($b->sourceDoc !== '' ? $b->sourceDoc : $b->id),
                        'supplierId' => $b->supplierId,
                        'supplierName' => $s->name ?? '',
                        'date' => $b->due ?? $today,
                        'amount' => $fx->toAud($b->balance(), $b->currency),
                        'currency' => 'AUD',
                        'kind' => $a->paymentIssues === [] ? 'Payment plan' : 'Invoice',
                        'blocked' => !$b->isPosted() || ($s === null || $s->hold || $b->paymentHold || !$s->bankVerified),
                        'invoiceId' => $b->id,
                    ];
                }
            }
        }
        foreach ($this->invoices->poForecast() as $p) {
            if (!isset($poSeen[$p->po]) && $p->amount > 0) {
                $forecast[] = [
                    'id' => $p->po,
                    'source' => $p->po,
                    'supplierId' => $p->supplierId,
                    'supplierName' => isset($suppliers[$p->supplierId]) ? $suppliers[$p->supplierId]->name : '',
                    'date' => $p->date,
                    'amount' => $fx->toAud($p->amount, $p->currency),
                    'currency' => 'AUD',
                    'kind' => 'PO estimate',
                    'blocked' => false,
                    'invoiceId' => null,
                ];
            }
        }
        usort($forecast, static fn (array $x, array $y): int => [$x['date'], $x['id']] <=> [$y['date'], $y['id']]);
        $outflow = 0.0;
        foreach ($forecast as $row) {
            if (!$row['blocked'] && $row['date'] >= $today && $row['date'] <= $horizon) {
                $outflow += $row['amount'];
            }
        }
        $forecast = array_map(static function (array $row): array {
            $row['amount'] = round($row['amount'], 2);

            return $row;
        }, $forecast);

        arsort($spend);
        $top = [];
        foreach (array_slice($spend, 0, self::TOP_SUPPLIERS, true) as $id => $total) {
            $top[] = ['supplierId' => (string) $id, 'name' => isset($suppliers[$id]) ? $suppliers[$id]->name : '', 'total' => round($total, 2)];
        }

        return [
            'kpis' => ['suppliers' => count($suppliers), 'openAp' => round($openAp, 2), 'overdueAp' => round($overdue, 2), 'outflow5d' => round($outflow, 2)],
            'queues' => $counts,
            'ageing' => array_map(static fn (float $v): float => round($v, 2), $ageing),
            'topSuppliers' => $top,
            'forecast' => $forecast,
            'fx' => ['currency' => 'AUD', 'unconverted' => $fx->missing()],
        ];
    }
}
