<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillState;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillUpdate;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchFacts;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchRequest;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooRates;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooTrackedBill;

/**
 * What {@see \TriCab\Core\Finance\Ap2\Odoo\Ap2OdooSyncJob} reads from and writes to AP2. Claude 1 implements it over the
 * AP2 services, so every write goes through the normal rules, history and transactions. Each `apply*` must be safe to
 * repeat with the same input (the job runs every 15 minutes and may run a slot twice).
 */
interface Ap2OdooSyncTarget
{
    /**
     * Invoices with an Odoo bill (`odooMoveId` set) that are not excluded and not yet Settled.
     *
     * @return list<Ap2OdooTrackedBill>
     */
    public function trackedBills(): array;

    /**
     * Records one bill's Odoo state: `importState`, `odooRef` and the ABSOLUTE `paid` from `$update` (do not add it to
     * what is there), the stage the rules then give, a history line when `$update->changed`, and the payment proposals
     * that follow (`Ap2PaymentService::recordOdooState()`: Sent to Odoo / Paid; mind that its Paid adds the amount to
     * `paid`, which this `paid` already includes). `$state->payments` are the Odoo payments reconciled with the bill.
     */
    public function applyBillState(Ap2OdooBillUpdate $update, Ap2OdooBillState $state): void;

    /** A tracked bill Odoo no longer has (deleted): flag the invoice for a person ("Sync failed"). */
    public function billMissing(Ap2OdooTrackedBill $bill): void;

    /**
     * Invoices waiting for PO / receipt matching that quote a PO number (Goods PO / Service PO, not excluded, not
     * posted).
     *
     * @return list<Ap2OdooMatchRequest>
     */
    public function awaitingMatching(): array;

    /**
     * Fills the invoice's `orderedQty`, `receivedQty`, `poUnitPrice` (when not null) and `receiptRef` from Odoo, and
     * re-runs the rules. When `$facts->found` is false, leave the fields and note the issue.
     */
    public function applyPurchaseOrder(Ap2OdooMatchFacts $facts): void;

    /** Odoo's AUD rates for the dashboard and ageing (`Ap2Settings::$fxRates`). Only the currencies present change. */
    public function applyCurrencyRates(Ap2OdooRates $rates): void;
}
