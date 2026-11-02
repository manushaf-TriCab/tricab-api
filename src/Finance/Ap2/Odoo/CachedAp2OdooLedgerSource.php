<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Odoo;

use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooLedgerSource;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooLedger;

/**
 * Keeps Odoo's ledger for {@see self::TTL_SECONDS}, so moving between Suppliers, Aged payables and Statements does not
 * read Odoo each time. `fresh` (the Refresh button) reads it again. Statement balances are not kept.
 */
final class CachedAp2OdooLedgerSource implements Ap2OdooLedgerSource
{
    public const TTL_SECONDS = 300;

    private const KEY = 'finance.ap2.odoo-ledger.v1';

    public function __construct(
        private readonly Ap2OdooLedgerSource $inner,
        private readonly ApplicationCache $cache,
    ) {
    }

    public function ledger(bool $fresh = false): Ap2OdooLedger
    {
        if (!$fresh) {
            $kept = $this->cache->get(self::KEY);
            $ledger = is_array($kept) ? Ap2OdooLedger::fromArray($kept) : null;
            if ($ledger !== null) {
                return $ledger;
            }
        }
        $ledger = $this->inner->ledger(true);
        $this->cache->set(self::KEY, $ledger->toArray(), self::TTL_SECONDS);

        return $ledger;
    }

    public function payableLines(array $partnerIds, string $onOrBefore): array
    {
        return $this->inner->payableLines($partnerIds, $onOrBefore);
    }
}
