<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Odoo;

use TriCab\Core\Finance\Ap2\Contract\Ap2OdooSyncTarget;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillState;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillUpdate;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchFacts;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchRequest;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooRates;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooTrackedBill;

/** The sync job's AP2 side in a test: answers what is set, records what it is told. */
final class RecordingAp2OdooSyncTarget implements Ap2OdooSyncTarget
{
    /** @var list<Ap2OdooTrackedBill> */
    public array $bills = [];

    /** @var list<Ap2OdooMatchRequest> */
    public array $waiting = [];

    /** @var list<Ap2OdooBillUpdate> */
    public array $applied = [];

    /** @var list<string> */
    public array $missing = [];

    /** @var list<Ap2OdooMatchFacts> */
    public array $matched = [];

    /** @var list<Ap2OdooRates> */
    public array $rates = [];

    public function trackedBills(): array
    {
        return $this->bills;
    }

    public function applyBillState(Ap2OdooBillUpdate $update, Ap2OdooBillState $state): void
    {
        $this->applied[] = $update;
    }

    public function billMissing(Ap2OdooTrackedBill $bill): void
    {
        $this->missing[] = $bill->invoiceId;
    }

    public function awaitingMatching(): array
    {
        return $this->waiting;
    }

    public function applyPurchaseOrder(Ap2OdooMatchFacts $facts): void
    {
        $this->matched[] = $facts;
    }

    public function applyCurrencyRates(Ap2OdooRates $rates): void
    {
        $this->rates[] = $rates;
    }
}
