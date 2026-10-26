<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Odoo;

use TriCab\Core\Finance\Ap2\Contract\Ap2OdooGateway;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBankAccount;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillState;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostedBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPurchaseOrder;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooRates;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * Odoo in memory, for tests and local development: set the purchase orders, bill states, rates and bank accounts it
 * answers; `postBill` keeps the bills and is idempotent on the external id, as the real one is. `$refuse` names reads
 * that fail as Odoo would without Accounting rights (`billStates`, `purchaseOrders`, `currencyRates`,
 * `supplierBankAccounts`, `postBill`).
 */
final class FakeAp2OdooGateway implements Ap2OdooGateway
{
    /** @var array<string, Ap2OdooPurchaseOrder> by PO number */
    public array $orders = [];

    /** @var array<int, Ap2OdooBillState> by move id */
    public array $states = [];

    /** @var array<string, float> AUD per unit */
    public array $rates = ['AUD' => 1.0];

    /** @var array<int, list<Ap2OdooBankAccount>> by partner id */
    public array $banks = [];

    /** @var array<string, array{bill: Ap2OdooBill, moveId: int}> by external id */
    public array $posted = [];

    /** @var list<string> */
    public array $refuse = [];

    /** @var list<string> the methods called, in order */
    public array $calls = [];

    public function __construct(public bool $postingEnabled = false)
    {
    }

    public function purchaseOrders(array $poNumbers): array
    {
        $this->call('purchaseOrders');

        return array_intersect_key($this->orders, array_flip($poNumbers));
    }

    public function billStates(array $moveIds): array
    {
        $this->call('billStates');

        return array_intersect_key($this->states, array_flip($moveIds));
    }

    public function currencyRates(string $date): Ap2OdooRates
    {
        $this->call('currencyRates');

        return new Ap2OdooRates($date, $this->rates, array_map(static fn (): string => $date, array_diff_key($this->rates, ['AUD' => true])));
    }

    public function supplierBankAccounts(array $partnerIds): array
    {
        $this->call('supplierBankAccounts');

        return array_intersect_key($this->banks, array_flip($partnerIds));
    }

    public function postBill(Ap2OdooBill $bill): Ap2OdooPostedBill
    {
        if (!$this->postingEnabled) {
            throw new Ap2OdooPostingDisabled();
        }
        $this->call('postBill');
        if (isset($this->posted[$bill->externalId])) {
            return new Ap2OdooPostedBill($this->posted[$bill->externalId]['moveId'], '/', false);
        }
        $moveId = 9000 + count($this->posted) + 1;
        $this->posted[$bill->externalId] = ['bill' => $bill, 'moveId' => $moveId];

        return new Ap2OdooPostedBill($moveId, '/', true);
    }

    private function call(string $method): void
    {
        $this->calls[] = $method;
        if (in_array($method, $this->refuse, true)) {
            throw new OdooError('Odoo ' . $method . ' returned HTTP 403: You are not allowed to access this document.');
        }
    }
}
