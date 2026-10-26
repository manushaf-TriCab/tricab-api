<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use InvalidArgumentException;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooGateway;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;
use TriCab\Core\Finance\Ap2\Domain\Ap2Upstream;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostedBill;
use TriCab\Core\Finance\Shared\Odoo\OdooError;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordStore;

/**
 * `POST /invoices/{id}/post-to-odoo` (EXPORT): creates the invoice, and its credit note, in Odoo as DRAFT vendor bills
 * through {@see Ap2OdooGateway::postBill()}. Never posts or confirms: Finance checks the draft in Odoo.
 *
 * Refused (409, reasons in `error.details.issues`) while FINANCE_AP2_ODOO_POSTING_ENABLED is off or Odoo is not
 * configured, when the invoice already has an Odoo bill, when it would not pass the CSV export checks (valid, currently
 * approved, not posted, not exported or imported), and when an Odoo id it needs cannot be resolved.
 *
 * The ids come from the platform's copy of Odoo (`finance.odoo_records`, the daily `odoo.sync` job):
 *  - vendor:   the supplier's `odooPartnerId`;
 *  - company:  the invoice's entity, by name, among the `companies`;
 *  - account:  the GL code, among the `accounts` that belong to that company;
 *  - analytic: the cost centre, by code or name, among the `cost-centres` (shared or of that company);
 *  - taxes:    the `taxes` list (purchase `account.tax`, synced only while FINANCE_AP2_ENABLED), within the invoice's
 *              company: the settings' `tax_code_map` first, else the tax code against a tax's name or label
 *              (case-insensitive). Ambiguous or missing is refused. An invoice without tax needs none.
 */
final class Ap2OdooPostingService
{
    public function __construct(
        private readonly Ap2OdooGateway $odoo,
        private readonly OdooRecordStore $records,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2Assessor $assessor,
        private readonly Ap2Transactions $transactions,
        private readonly ApTime $time,
        private readonly bool $postingEnabled,
        private readonly ?Ap2SettingsStore $settings = null,
    ) {
    }

    /** @return array<string, mixed> the invoice */
    public function post(Ap2Actor $actor, string $id): array
    {
        $invoice = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->invoices->find($id) : null;
        if ($invoice === null) {
            throw new Ap2NotFound('Invoice not found');
        }
        if (!$this->postingEnabled) {
            throw new Ap2Conflict('Posting to Odoo is switched off', Ap2Failure::details(['FINANCE_AP2_ODOO_POSTING_ENABLED is off, or Odoo is not configured on this host']));
        }
        if ($invoice->odooMoveId !== null) {
            throw new Ap2Conflict('The invoice is already in Odoo', Ap2Failure::details(['Odoo bill ' . $invoice->odooMoveId . ($invoice->odooRef !== '' ? ' (' . $invoice->odooRef . ')' : '') . ' exists']));
        }
        $supplier = $this->suppliers->find($invoice->supplierId);
        $a = $this->assessor->one($invoice, $supplier, $this->time->today());
        if (!$a->csvEligible) {
            throw new Ap2Conflict('The invoice cannot be posted to Odoo yet', Ap2Failure::details($a->exportIssues));
        }

        $issues = [];
        $ids = $this->resolve($invoice, $supplier, $issues);
        if ($issues !== [] || $ids === null) {
            throw new Ap2Conflict('The invoice cannot be posted to Odoo yet', Ap2Failure::details($issues));
        }
        try {
            $bills = Ap2OdooBill::forInvoice($invoice, $ids['partner'], $ids['company'], $ids['account'], $ids['taxes'], $ids['analytic']);
        } catch (InvalidArgumentException $e) {
            throw new Ap2Conflict('The invoice cannot be posted to Odoo yet', Ap2Failure::details([$e->getMessage()]));
        }

        /** @var list<Ap2OdooPostedBill> $posted */
        $posted = [];
        $failure = null;
        foreach ($bills as $bill) {
            try {
                $posted[] = $this->odoo->postBill($bill);
            } catch (OdooError $e) {
                $failure = $e;
                break;
            }
        }
        if ($posted === []) {
            throw new Ap2Upstream('Odoo did not take the bill', Ap2Failure::details([$failure?->getMessage() ?? 'Odoo did not answer']), $failure);
        }

        $first = $posted[0];
        $now = $this->time->now();
        $ref = $first->name !== '' && $first->name !== '/' ? $first->name : '';
        $changed = $invoice->with(importState: 'Imported draft', odooRef: $ref, odooMoveId: $first->moveId, updatedAt: $now);
        $changed = $changed->with(stage: Ap2InvoiceRules::stage($changed, $a->validation, $a->approvalCurrent));
        $moves = array_map(static fn (Ap2OdooPostedBill $p): int => $p->moveId, $posted);

        $this->transactions->run(function () use ($changed, $actor, $now, $moves, $first, $failure, $bills): void {
            $this->invoices->update($changed);
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $changed->supplierId,
                'Posted to Odoo as draft',
                $changed->number . ' · Odoo draft ' . implode(', ', $moves) . ($first->created ? '' : ' (already there)') . ' · not confirmed'
                    . ($failure === null ? '' : ' · the credit note failed: ' . $failure->getMessage() . ' (' . (count($bills) - count($moves)) . ' not created)'),
                $actor->person(),
                $now,
                $changed->id,
                null,
                ['odooMoveIds' => $moves],
            ));
        });

        if ($failure !== null) {
            throw new Ap2Upstream('The bill is in Odoo, but its credit note is not', Ap2Failure::details([$failure->getMessage(), 'Post the credit note in Odoo by hand, or import it from the CSV']), $failure);
        }

        $supplier = $this->suppliers->find($changed->supplierId);

        return Ap2Presenter::invoice($changed, $supplier, $this->assessor->one($changed, $supplier, $this->time->today()));
    }

    /**
     * @param list<string> $issues filled with what could not be resolved
     * @return array{partner: int, company: int, account: int|null, taxes: list<int>, analytic: int|null}|null
     */
    private function resolve(Ap2Invoice $b, ?Ap2Supplier $supplier, array &$issues): ?array
    {
        $partner = $supplier?->odooPartnerId;
        if ($partner === null || $partner <= 0) {
            $issues[] = 'Vendor: ' . ($supplier->name ?? 'the supplier') . ' has no Odoo partner id (set it on the supplier)';
        }

        $company = null;
        if (trim($b->entity) === '') {
            $issues[] = 'Company: the invoice has no entity';
        } else {
            foreach ($this->records->list(OdooLists::COMPANIES, false, null) as $r) {
                if (strcasecmp(self::text($r->payload['name'] ?? null), trim($b->entity)) === 0) {
                    $company = $r->odooId;
                    break;
                }
            }
            if ($company === null) {
                $issues[] = 'Company: the entity "' . $b->entity . '" matches no Odoo company (companies copied: the daily Odoo sync)';
            }
        }

        $account = null;
        if (trim($b->gl) === '') {
            $issues[] = 'Account: the invoice has no GL code';
        } else {
            $anyAccount = false;
            foreach ($this->records->list(OdooLists::ACCOUNTS, false, null) as $r) {
                if (self::text($r->payload['code'] ?? null) !== trim($b->gl)) {
                    continue;
                }
                $anyAccount = true;
                if ($company !== null && in_array($company, self::intList($r->payload['company_ids'] ?? null), true)) {
                    $account = $r->odooId;
                    break;
                }
            }
            if ($account === null) {
                $issues[] = $anyAccount
                    ? 'Account: GL ' . $b->gl . ' exists in Odoo but not in the invoice\'s company'
                    : 'Account: GL ' . $b->gl . ' matches no Odoo account';
            }
        }

        $analytic = null;
        if (trim($b->costCentre) !== '') {
            $analytic = $this->costCentre($b->costCentre, $company);
            if ($analytic === null) {
                $issues[] = 'Cost centre: "' . $b->costCentre . '" matches no Odoo analytic account (by code or name)';
            }
        }

        $taxes = [];
        if (abs($b->tax) >= 0.005 || abs($b->creditTax) >= 0.005) {
            $tax = $this->tax($b->taxCode, $company, $issues);
            if ($tax !== null) {
                $taxes = [$tax];
            }
        }

        if ($partner === null || $partner <= 0 || $company === null) {
            return null;
        }

        return ['partner' => $partner, 'company' => $company, 'account' => $account, 'taxes' => $taxes, 'analytic' => $analytic];
    }

    /** @param list<string> $issues */
    private function tax(string $code, ?int $company, array &$issues): ?int
    {
        $code = trim($code);
        $label = 'Tax: code "' . $code . '"';
        if ($code === '') {
            $issues[] = 'Tax: the invoice has tax but no tax code';

            return null;
        }
        /** @var array<int, string> $candidates id => name, purchase taxes of the company */
        $candidates = [];
        $byName = [];
        foreach ($this->records->list(OdooLists::TAXES, false, null) as $r) {
            $owner = is_numeric($r->payload['company_id'] ?? null) ? (int) $r->payload['company_id'] : null;
            if (self::text($r->payload['type_tax_use'] ?? null) !== 'purchase' || ($company !== null && $owner !== $company)) {
                continue;
            }
            $name = self::text($r->payload['name'] ?? null);
            $candidates[$r->odooId] = $name;
            foreach ([$name, self::text($r->payload['description'] ?? null)] as $text) {
                if ($text !== '' && strcasecmp($text, $code) === 0) {
                    $byName[$r->odooId] = $name;
                }
            }
        }
        $mapped = $this->settings?->get()->taxCodeMap[$code] ?? null;
        if ($mapped === null && $this->settings !== null) {
            foreach ($this->settings->get()->taxCodeMap as $k => $v) {
                if (strcasecmp((string) $k, $code) === 0) {
                    $mapped = $v;
                }
            }
        }
        if ($mapped !== null) {
            if (isset($candidates[$mapped])) {
                return $mapped;
            }
            $issues[] = $label . ' is mapped to Odoo tax ' . $mapped . ', which is not a purchase tax of the invoice\'s company (check the settings tax_code_map)';

            return null;
        }
        if (count($byName) === 1) {
            return array_key_first($byName);
        }
        if ($byName === []) {
            $issues[] = $label . ' matches no Odoo purchase tax of the invoice\'s company (by name or label; taxes copied: the daily Odoo sync, or map it in the settings)';

            return null;
        }
        $names = [];
        foreach ($byName as $id => $name) {
            $names[] = $name . ' (' . $id . ')';
        }
        $issues[] = $label . ' is ambiguous: it matches ' . implode(', ', $names) . ' (map it in the settings tax_code_map)';

        return null;
    }

    private function costCentre(string $wanted, ?int $company): ?int
    {
        $wanted = strtolower(trim($wanted));
        $byName = null;
        foreach ($this->records->list(OdooLists::COST_CENTRES, false, null) as $r) {
            $owner = is_numeric($r->payload['company_id'] ?? null) ? (int) $r->payload['company_id'] : null;
            if ($owner !== null && $company !== null && $owner !== $company) {
                continue;
            }
            if (strtolower(self::text($r->payload['code'] ?? null)) === $wanted) {
                return $r->odooId;
            }
            if ($byName === null && strtolower(self::text($r->payload['name'] ?? null)) === $wanted) {
                $byName = $r->odooId;
            }
        }

        return $byName;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $v) {
            if (is_numeric($v)) {
                $out[] = (int) $v;
            }
        }

        return $out;
    }
}
