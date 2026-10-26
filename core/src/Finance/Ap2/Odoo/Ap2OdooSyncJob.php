<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooGateway;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooSyncTarget;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * Every 15 minutes (Phase 9): reads back the Odoo state of AP2 bills (Posted / Partially settled / Settled, payments),
 * refreshes PO and receipt data for invoices awaiting matching, and takes Odoo's AUD rates. READ ONLY towards Odoo.
 *
 * The three parts are independent: one that Odoo refuses (the key's user may lack Accounting rights, plan question 10)
 * does not stop the others. When any part failed the job fails after the rest is done, naming each, so the run's
 * audit row says so. Safe to repeat: every write is absolute ({@see Ap2OdooSyncTarget}).
 */
final class Ap2OdooSyncJob implements FinanceJob
{
    public const NAME = 'ap2.odoo-sync';

    private const MINUTES = 15;

    /** Odoo is asked for at most this many ids or numbers at once. */
    private const BATCH = 200;

    public function __construct(
        private readonly Ap2OdooGateway $odoo,
        private readonly Ap2OdooSyncTarget $target,
        private readonly string $timeZone = 'Australia/Sydney',
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function schedule(): FinanceJobSchedule
    {
        return FinanceJobSchedule::everyMinutes(self::MINUTES);
    }

    public function run(DateTimeImmutable $now): array
    {
        $facts = [];
        $failed = [];

        try {
            $facts += $this->bills();
        } catch (OdooError $e) {
            $failed[] = 'bill states: ' . $e->getMessage();
        }
        try {
            $facts += $this->purchaseOrders();
        } catch (OdooError $e) {
            $failed[] = 'purchase orders: ' . $e->getMessage();
        }
        try {
            $facts += $this->rates($now->setTimezone(new DateTimeZone($this->timeZone))->format('Y-m-d'));
        } catch (OdooError $e) {
            $failed[] = 'currency rates: ' . $e->getMessage();
        }

        if ($failed !== []) {
            throw new RuntimeException('The Tri-AP2 Odoo sync could not read ' . implode('; ', $failed));
        }

        return $facts;
    }

    /** @return array<string, int> */
    private function bills(): array
    {
        $tracked = $this->target->trackedBills();
        $counts = ['billsTracked' => count($tracked), 'billsChanged' => 0, 'billsSettled' => 0, 'billsMissing' => 0, 'billIssues' => 0];
        if ($tracked === []) {
            return $counts;
        }
        $states = [];
        foreach (array_chunk(array_values(array_unique(array_map(static fn (Ap2OdooTrackedBill $b): int => $b->moveId, $tracked))), self::BATCH) as $ids) {
            $states += $this->odoo->billStates($ids);
        }
        foreach ($tracked as $bill) {
            $state = $states[$bill->moveId] ?? null;
            if ($state === null) {
                $this->target->billMissing($bill);
                ++$counts['billsMissing'];
                continue;
            }
            $update = Ap2OdooBillMapping::map($bill, $state);
            if (!$update->changed) {
                continue;
            }
            $this->target->applyBillState($update, $state);
            ++$counts['billsChanged'];
            $counts['billsSettled'] += $update->settled ? 1 : 0;
            $counts['billIssues'] += $update->issues === [] ? 0 : 1;
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function purchaseOrders(): array
    {
        $waiting = $this->target->awaitingMatching();
        $counts = ['matchingWaiting' => count($waiting), 'poFound' => 0, 'poMissing' => 0];
        if ($waiting === []) {
            return $counts;
        }
        $orders = [];
        foreach (array_chunk(array_values(array_unique(array_map(static fn (Ap2OdooMatchRequest $r): string => $r->po, $waiting))), self::BATCH) as $numbers) {
            $orders += $this->odoo->purchaseOrders($numbers);
        }
        foreach ($waiting as $request) {
            $facts = Ap2OdooMatchFacts::of($request, $orders[$request->po] ?? null);
            $this->target->applyPurchaseOrder($facts);
            ++$counts[$facts->found ? 'poFound' : 'poMissing'];
        }

        return $counts;
    }

    /** @return array<string, int|string> */
    private function rates(string $today): array
    {
        $rates = $this->odoo->currencyRates($today);
        if (count($rates->audPerUnit) > 1) {
            $this->target->applyCurrencyRates($rates);
        }

        return ['ratesRead' => count($rates->audPerUnit) - 1, 'ratesMissing' => implode(',', $rates->missing())];
    }
}
