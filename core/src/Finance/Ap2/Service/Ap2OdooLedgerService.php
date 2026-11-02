<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooLedgerSource;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Statement;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;
use TriCab\Core\Finance\Ap2\Domain\Ap2Upstream;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooLedger;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * Suppliers, Aged payables and Supplier statements read Odoo's payables live (decided 2 Nov 2026, Nick):
 *
 * - every Odoo supplier with a posted bill or an open line has a Tri-AP supplier, linked by `odooPartnerId`. Name,
 *   email, phone, industry, terms and entity come from Odoo on every read; owner, hold, CC, notes and bank
 *   verification stay Tri-AP's;
 * - a supplier's balance is Odoo's open payable lines netted, in AUD; payments not matched to a bill are netted in and
 *   shown as `unallocated`;
 * - lines not booked to a supplier (the opening balances from 4js) are one "not by supplier" row per entity.
 */
final class Ap2OdooLedgerService
{
    public const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90p'];

    /** Supplier fields Odoo owns: a PATCH may not change them on a linked supplier. */
    public const ODOO_FIELDS = ['name', 'email', 'phone', 'industry', 'terms', 'entity'];

    public function __construct(
        private readonly Ap2OdooLedgerSource $source,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2Transactions $transactions,
        private readonly Ap2Time $time,
    ) {
    }

    /** @throws Ap2Upstream when Odoo cannot be read */
    public function ledger(bool $fresh = false): Ap2OdooLedger
    {
        try {
            return $this->source->ledger($fresh);
        } catch (OdooError $e) {
            throw new Ap2Upstream('Odoo could not be read', Ap2Failure::details([$e->getMessage()]), $e);
        }
    }

    /**
     * Every Tri-AP supplier, with one for each Odoo supplier in the ledger: new ones are added and the Odoo-owned
     * fields of linked ones brought up to date. Writes only what changed.
     *
     * @return list<Ap2Supplier>
     */
    public function link(Ap2OdooLedger $ledger): array
    {
        $all = $this->suppliers->all();
        $byPartner = [];
        foreach ($all as $s) {
            if ($s->odooPartnerId !== null) {
                $byPartner[$s->odooPartnerId] = $s;
            }
        }
        $inserts = [];
        $updates = [];
        $now = $this->time->now();
        foreach ($ledger->vendors as $partnerId => $v) {
            $current = $byPartner[$partnerId] ?? null;
            $odoo = [
                'name' => $v->name,
                'email' => $v->email,
                'phone' => $v->phone,
                'industry' => $v->industry,
                'entity' => $v->entity,
                'terms' => $v->terms ?? $current->terms ?? 30,
            ];
            if ($current === null) {
                $inserts[] = (new Ap2Supplier(bin2hex(random_bytes(16)), $v->name, $now, $now, odooPartnerId: $partnerId))->with(...$odoo);
                continue;
            }
            $changed = $current->with(...$odoo);
            if ($changed != $current) {
                $updates[] = $changed->with(updatedAt: $now);
            }
        }
        if ($inserts === [] && $updates === []) {
            return $all;
        }
        $this->transactions->run(function () use ($inserts, $updates): void {
            foreach ($inserts as $s) {
                $this->suppliers->insert($s);
            }
            foreach ($updates as $s) {
                $this->suppliers->update($s);
            }
        });

        return $this->suppliers->all();
    }

    public const NO_POSITION = ['balance' => 0.0, 'overdue' => 0.0, 'openInvoices' => 0, 'unallocated' => 0.0];

    /**
     * The suppliers (linked to Odoo's first) with their positions, or, when Odoo cannot be read, the suppliers Tri-AP
     * already holds and no positions: a supplier list still opens without Odoo.
     *
     * @return array{suppliers: list<Ap2Supplier>, positions: array<string, array{balance: float, overdue: float, openInvoices: int, unallocated: float}>|null, readAt: DateTimeImmutable|null}
     */
    public function suppliersWithPositions(bool $fresh = false): array
    {
        try {
            $ledger = $this->ledger($fresh);
        } catch (Ap2Upstream) {
            return ['suppliers' => $this->suppliers->all(), 'positions' => null, 'readAt' => null];
        }
        $suppliers = $this->link($ledger);

        return ['suppliers' => $suppliers, 'positions' => $this->positions($ledger, $suppliers), 'readAt' => $ledger->readAt];
    }

    /**
     * One supplier's position from the kept ledger, or null when Odoo cannot be read.
     *
     * @return array{position: array{balance: float, overdue: float, openInvoices: int, unallocated: float}, readAt: DateTimeImmutable}|null
     */
    public function positionOf(Ap2Supplier $supplier): ?array
    {
        try {
            $ledger = $this->ledger();
        } catch (Ap2Upstream) {
            return null;
        }

        return ['position' => $this->positions($ledger, [$supplier])[$supplier->id] ?? self::NO_POSITION, 'readAt' => $ledger->readAt];
    }

    /**
     * Per Tri-AP supplier id: what is owed (net, AUD), the part past due, the open bills, and the payments not matched
     * to a bill (a positive AUD amount, already netted into `balance`).
     *
     * @param list<Ap2Supplier> $suppliers
     * @return array<string, array{balance: float, overdue: float, openInvoices: int, unallocated: float}>
     */
    public function positions(Ap2OdooLedger $ledger, array $suppliers): array
    {
        $idOf = self::idsByPartner($suppliers);
        $fx = $this->fx();
        $today = $this->time->today();
        $out = [];
        foreach ($ledger->openLines as $line) {
            $id = $line->partnerId === null ? null : ($idOf[$line->partnerId] ?? null);
            if ($id === null) {
                continue;
            }
            $row = $out[$id] ?? ['balance' => 0.0, 'overdue' => 0.0, 'openInvoices' => 0, 'unallocated' => 0.0];
            $owed = $fx->toAud($line->owed(), $line->companyCurrency);
            $row['balance'] += $owed;
            if (self::daysPast($line->agedFrom(), $today) > 0) {
                $row['overdue'] += $owed;
            }
            if ($line->isBill()) {
                ++$row['openInvoices'];
            }
            if ($line->isUnallocatedPayment()) {
                $row['unallocated'] -= $owed;
            }
            $out[$id] = $row;
        }

        return $out;
    }

    /**
     * `GET /reports/aged`: per supplier with anything open, then one "not by supplier" row per entity, ageing by due
     * date in AUD. Supplier rows by name.
     *
     * @return list<array<string, mixed>>
     */
    public function aged(bool $fresh = false): array
    {
        $ledger = $this->ledger($fresh);
        $suppliers = $this->link($ledger);
        $idOf = self::idsByPartner($suppliers);
        $fx = $this->fx();
        $today = $this->time->today();
        $rows = [];
        $loose = [];
        foreach ($ledger->openLines as $line) {
            $amount = $fx->toAud($line->owed(), $line->companyCurrency);
            $key = self::bucket(self::daysPast($line->agedFrom(), $today));
            $id = $line->partnerId === null ? null : ($idOf[$line->partnerId] ?? null);
            if ($id === null) {
                $entity = $ledger->entity($line->companyId);
                $loose[$entity] ??= array_fill_keys(self::BUCKETS, 0.0);
                $loose[$entity][$key] += $amount;
                continue;
            }
            $rows[$id] ??= array_fill_keys(self::BUCKETS, 0.0) + ['unallocated' => 0.0];
            $rows[$id][$key] += $amount;
            if ($line->isUnallocatedPayment()) {
                $rows[$id]['unallocated'] -= $amount;
            }
        }

        $out = [];
        foreach ($suppliers as $s) {
            if (!isset($rows[$s->id])) {
                continue;
            }
            $r = $rows[$s->id];
            $unallocated = $r['unallocated'];
            unset($r['unallocated']);
            $out[] = self::agedRow($r) + [
                'supplierId' => $s->id,
                'supplierName' => $s->name,
                'entity' => $s->entity,
                'unallocated' => round($unallocated, 2),
                'onHold' => $s->hold || $s->bankChange || !$s->bankVerified,
                'notBySupplier' => false,
            ];
        }
        ksort($loose);
        foreach ($loose as $entity => $r) {
            $out[] = self::agedRow($r) + [
                'supplierId' => null,
                'supplierName' => 'Not by supplier (' . $entity . ')',
                'entity' => $entity,
                'unallocated' => 0.0,
                'onHold' => false,
                'notBySupplier' => true,
            ];
        }

        return $out;
    }

    /**
     * Odoo's balance for each statement's supplier as at the statement date, in the statement's currency (lines in
     * another currency are left out, as before): what the supplier is owed by our books on that day.
     *
     * @param list<Ap2Statement> $statements
     * @return array<string, float|null> by statement id; null when the supplier is not linked to Odoo
     */
    public function statementBalances(array $statements): array
    {
        if ($statements === []) {
            return [];
        }
        $partnerOf = [];
        foreach ($this->suppliers->all() as $s) {
            if ($s->odooPartnerId !== null) {
                $partnerOf[$s->id] = $s->odooPartnerId;
            }
        }
        $partners = [];
        $latest = '';
        foreach ($statements as $st) {
            if (isset($partnerOf[$st->supplierId])) {
                $partners[$partnerOf[$st->supplierId]] = true;
                $latest = max($latest, $st->date);
            }
        }
        $lines = [];
        if ($partners !== []) {
            try {
                $lines = $this->source->payableLines(array_keys($partners), $latest);
            } catch (OdooError $e) {
                throw new Ap2Upstream('Odoo could not be read', Ap2Failure::details([$e->getMessage()]), $e);
            }
        }
        $out = [];
        foreach ($statements as $st) {
            $partnerId = $partnerOf[$st->supplierId] ?? null;
            if ($partnerId === null) {
                $out[$st->id] = null;
                continue;
            }
            $total = 0.0;
            foreach ($lines as $line) {
                if ($line->partnerId === $partnerId && $line->date <= $st->date && $line->currency === $st->currency) {
                    $total -= $line->amountCurrency;
                }
            }
            $out[$st->id] = round($total, 2);
        }

        return $out;
    }

    /**
     * @param array<string, float> $r
     * @return array<string, float>
     */
    private static function agedRow(array $r): array
    {
        $total = array_sum($r);

        return array_map(static fn (float $v): float => round($v, 2), $r) + [
            'total' => round($total, 2),
            'overdue' => round($total - $r['current'], 2),
        ];
    }

    /**
     * @param list<Ap2Supplier> $suppliers
     * @return array<int, string>
     */
    private static function idsByPartner(array $suppliers): array
    {
        $out = [];
        foreach ($suppliers as $s) {
            if ($s->odooPartnerId !== null) {
                $out[$s->odooPartnerId] = $s->id;
            }
        }

        return $out;
    }

    private static function bucket(int $days): string
    {
        return $days === 0 ? 'current' : ($days <= 30 ? 'd1_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'd90p')));
    }

    private static function daysPast(string $date, string $today): int
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $now = DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone('UTC'));
        if ($from === false || $now === false) {
            return 0;
        }

        return max(0, (int) $from->diff($now)->format('%r%a'));
    }

    private function fx(): Ap2Fx
    {
        return new Ap2Fx($this->settings->get()->fxRates);
    }

}
