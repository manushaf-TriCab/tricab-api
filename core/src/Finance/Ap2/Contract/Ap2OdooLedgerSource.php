<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooLedger;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPayableLine;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * Odoo's payables, read live through the API for Suppliers, Aged payables and Supplier statements. READ ONLY. An
 * implementation may keep an answer for a few minutes; `$fresh` reads Odoo again.
 */
interface Ap2OdooLedgerSource
{
    /** @throws OdooError */
    public function ledger(bool $fresh = false): Ap2OdooLedger;

    /**
     * Every posted payable line of these suppliers dated on or before `$onOrBefore`, reconciled or not: a balance as
     * at that day is the sum of their `balance` (or `amountCurrency` in one currency).
     *
     * @param list<int> $partnerIds
     * @return list<Ap2OdooPayableLine>
     * @throws OdooError
     */
    public function payableLines(array $partnerIds, string $onOrBefore): array;
}
