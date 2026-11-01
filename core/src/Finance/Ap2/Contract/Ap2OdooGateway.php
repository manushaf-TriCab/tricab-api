<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBankAccount;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillState;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostedBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPurchaseOrder;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooRates;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * Tri-AP's view of Odoo 19 (Phases 6 and 9): reads for PO / receipt matching, bill state read-back, exchange rates and
 * the bank-change check, and ONE write, a draft vendor bill, which works only while FINANCE_AP2_ODOO_POSTING_ENABLED is
 * on. Every read throws {@see OdooError} when Odoo cannot be read (including refused Accounting rights); none answers
 * "nothing" for "could not read".
 */
interface Ap2OdooGateway
{
    /**
     * The purchase orders with these numbers (`purchase.order.name`), with lines and receipts. Unknown numbers are absent.
     *
     * @param list<string> $poNumbers
     * @return array<string, Ap2OdooPurchaseOrder> by PO number
     * @throws OdooError
     */
    public function purchaseOrders(array $poNumbers): array;

    /**
     * The state, payment state, residual and reconciled payments of these vendor bills. Unknown ids are absent.
     *
     * @param list<int> $moveIds
     * @return array<int, Ap2OdooBillState> by move id
     * @throws OdooError
     */
    public function billStates(array $moveIds): array;

    /**
     * AUD per unit of USD, NZD and SGD on `$date` (the latest rate on or before it), from the AUD company's rates.
     *
     * @throws OdooError
     */
    public function currencyRates(string $date): Ap2OdooRates;

    /**
     * The bank accounts of these vendors (`res.partner.bank`), masked, with when each last changed. Archived accounts
     * are included (marked inactive): a recently archived account is part of a bank change.
     *
     * @param list<int> $partnerIds
     * @return array<int, list<Ap2OdooBankAccount>> by partner id; a vendor without accounts is absent
     * @throws OdooError
     */
    public function supplierBankAccounts(array $partnerIds): array;

    /**
     * Creates the bill in Odoo as a DRAFT, unless it is already there: the external id `triap_<id>` is looked up first
     * (so a retry, or a bill imported from the CSV, answers the existing move), then the same vendor, reference and
     * type. Never posts or confirms it.
     *
     * @throws Ap2OdooPostingDisabled while posting is switched off; nothing is sent then
     * @throws OdooError
     */
    public function postBill(Ap2OdooBill $bill): Ap2OdooPostedBill;
}
