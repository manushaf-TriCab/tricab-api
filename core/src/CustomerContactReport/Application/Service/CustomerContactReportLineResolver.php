<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportDecimal as Dec;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportLine;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsLine;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsUnavailable;

/**
 * Rebuilds the lines a raiser ticked from 4JS. The client only says WHICH lines
 * and, optionally, how much of each; product, quantity and money always come from
 * 4JS, so a hand-edited request cannot put a different figure on a credit.
 * A line 4JS cannot confirm is dropped.
 *
 * Partial credit (live cc_apply_credit): quantity above 0 and at most the 4JS
 * quantity; rate from 0 up to the invoiced unit price; a typed net from 0 up to
 * the line net. Anything outside that is replaced by the 4JS figure, and the line
 * is flagged $capped so the screen can say so.
 */
final class CustomerContactReportLineResolver
{
    public function __construct(
        private readonly CustomerContactFourJsLookup $fourJs,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $wanted each: orderNo, lineNo, optional creditQty, creditRate, creditNet
     * @return array{lines: list<CustomerContactReportLine>, unavailable: bool, unconfirmed: int}
     */
    public function resolve(array $wanted): array
    {
        $byOrder = [];
        $asked = [];
        foreach ($wanted as $item) {
            $order = CustomerContactFourJsLookup::reference(is_scalar($item['orderNo'] ?? null) ? (string) $item['orderNo'] : '');
            $lineNo = is_numeric($item['lineNo'] ?? null) ? (int) $item['lineNo'] : 0;
            if ($order === '' || $lineNo <= 0) {
                continue;
            }
            $byOrder[$order][$lineNo] = true;
            $asked[$order . ':' . $lineNo] = $item;
        }

        $resolved = [];
        $unavailable = false;
        foreach ($byOrder as $orderNo => $lineNos) {
            try {
                $order = $this->fourJs->order((string) $orderNo);
                if ($order === null) {
                    continue;
                }
                $invoiceOf = [];
                $rateOf = [];
                foreach ($this->fourJs->orderInvoices((string) $orderNo) as $invoice) {
                    foreach ($invoice->lines as $line) {
                        $invoiceOf[$line->lineNo] = $invoice->invoiceNo;
                        $rateOf[$line->lineNo] = $invoice->gstRate;
                    }
                }
            } catch (FourJsUnavailable) {
                $unavailable = true;
                continue;
            }
            foreach ($order->lines as $line) {
                if (!isset($lineNos[$line->lineNo])) {
                    continue;
                }
                $line = $line->withInvoice($invoiceOf[$line->lineNo] ?? '');
                $rate = $rateOf[$line->lineNo] ?? (strtoupper($order->currency) === 'NZD' ? '0.15' : null);
                $resolved[$line->key()] = $this->credited($line, $asked[$line->key()] ?? [], $rate);
            }
        }
        $lines = array_values($resolved);
        usort($lines, static fn (CustomerContactReportLine $a, CustomerContactReportLine $b): int
            => [$a->orderNo, $a->lineNo] <=> [$b->orderNo, $b->lineNo]);

        return [
            'lines' => $lines,
            'unavailable' => $unavailable,
            'unconfirmed' => max(0, count($asked) - count($lines)),
        ];
    }

    /**
     * @param array<string, mixed> $ask
     */
    private function credited(FourJsLine $line, array $ask, ?string $gstRate): CustomerContactReportLine
    {
        $capped = false;

        $qty = $line->qty;
        if (array_key_exists('creditQty', $ask) && Dec::isNumeric($ask['creditQty'])) {
            $want = Dec::normalise($ask['creditQty']);
            if (Dec::cmp($want, '0', 6) <= 0 || Dec::cmp($want, $line->qty, 6) > 0) {
                $capped = true;
            } else {
                $qty = $want;
            }
        }

        $rate = $line->unitPrice;
        if (array_key_exists('creditRate', $ask) && Dec::isNumeric($ask['creditRate'])) {
            $want = Dec::normalise($ask['creditRate']);
            if (Dec::cmp($want, '0', 6) < 0 || Dec::cmp($want, Dec::add($line->unitPrice, '0.00005', 6), 6) > 0) {
                $capped = true;
            } else {
                $rate = $want;
            }
        }

        $net = Dec::money(Dec::mul($qty, $rate, 8));
        if (array_key_exists('creditNet', $ask) && Dec::isNumeric($ask['creditNet'])) {
            $want = Dec::normalise($ask['creditNet']);
            if (Dec::cmp($want, '0', 6) >= 0 && Dec::cmp($want, Dec::add($line->net, '0.005', 6), 6) <= 0) {
                $net = Dec::money($want);
            } else {
                $capped = true;
            }
        }
        $net = Dec::min($net, Dec::money($line->net));

        return new CustomerContactReportLine(
            orderNo: $line->orderNo,
            invoiceNo: $line->invoiceNo,
            lineNo: $line->lineNo,
            productCode: $line->productCode,
            description: mb_substr($line->description, 0, 255),
            qty: $line->qty,
            unit: $line->unit,
            packs: $line->packs,
            packSize: $line->packSize,
            unitPrice: $line->unitPrice,
            net: Dec::money($line->net),
            creditQty: $qty,
            creditRate: Dec::round($rate, 4),
            creditNet: $net,
            warehouse: $line->warehouse,
            comment: mb_substr($line->comment, 0, 500),
            gstRate: $gstRate,
            capped: $capped,
        );
    }
}
