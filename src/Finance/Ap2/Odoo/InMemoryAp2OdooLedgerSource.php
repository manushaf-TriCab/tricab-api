<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Odoo;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooLedgerSource;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooLedger;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPayableLine;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooVendor;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * Odoo's payables in memory, for tests and for a stack without Odoo (then empty). `$lines` holds every payable line,
 * reconciled or not; the ledger is the unreconciled ones. `$refuse` makes every read fail as an unreachable Odoo would.
 */
final class InMemoryAp2OdooLedgerSource implements Ap2OdooLedgerSource
{
    /** @var list<Ap2OdooPayableLine> */
    public array $lines = [];

    /** @var array<int, Ap2OdooVendor> by partner id */
    public array $vendors = [];

    /** @var array<int, string> */
    public array $entities = [1 => 'SG', 2 => 'AU', 4 => 'NZ'];

    public bool $refuse = false;

    public int $reads = 0;

    public function ledger(bool $fresh = false): Ap2OdooLedger
    {
        $this->check();
        ++$this->reads;

        return new Ap2OdooLedger(
            array_values(array_filter($this->lines, static fn (Ap2OdooPayableLine $l): bool => !$l->reconciled)),
            $this->vendors,
            $this->entities,
            new DateTimeImmutable('2026-11-02T00:00:00+00:00'),
        );
    }

    public function payableLines(array $partnerIds, string $onOrBefore): array
    {
        $this->check();

        return array_values(array_filter(
            $this->lines,
            static fn (Ap2OdooPayableLine $l): bool => $l->partnerId !== null && in_array($l->partnerId, $partnerIds, true) && $l->date <= $onOrBefore,
        ));
    }

    private function check(): void
    {
        if ($this->refuse) {
            throw new OdooError('Odoo did not answer.');
        }
    }
}
