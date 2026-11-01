<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2ExportStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Export;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;

/**
 * The Odoo CSV (Phase 6) and recording what happened to it in Odoo.
 *
 * Export (Nick's 1 Nov update): any saved invoice, with no verification, matching, approval, duplicate, prior-export
 * or posting gate, and a repeat export keeps the same External ID. The file follows the supplied Odoo vendor-bill
 * template (`odoo_vendor_bills_template.csv`): its 12 columns in its order, dates as d/m/yyyy, one row per bill line,
 * and the bill-level columns left blank on the continuation lines. A credit note is its own document and is not
 * netted into the bill lines. Each batch is saved (`CSV-0001`...) and can be downloaded again unchanged. Exported is
 * not imported and not posted: Not exported / Import failed become Exported CSV; Imported draft and Posted stay.
 *
 * Import result: only an exported (or already imported / failed) invoice; Imported draft and Posted need a current
 * approval and clean checks; the person must confirm the outcome; Posted moves the stage to Posted.
 */
final class Ap2ExportService
{
    public const HEADERS = ['External ID', 'Vendor', 'Bill Date', 'Due Date', 'Bill Reference', 'Journal', 'Currency', 'Invoice Lines/Label', 'Invoice Lines/Account', 'Invoice Lines/Quantity', 'Invoice Lines/Unit Price', 'Invoice Lines/Taxes'];

    /** The bill-level columns (External ID to Currency) a continuation line leaves blank. */
    private const BILL_COLUMNS = 7;

    public const RESULTS = ['Imported draft', 'Posted', 'Import failed'];

    private const MAX_INVOICES = 500;

    public function __construct(
        private readonly Ap2ExportStore $exports,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2Assessor $assessor,
        private readonly Ap2Transactions $transactions,
        private readonly ApTime $time,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(static fn (Ap2Export $x): array => Ap2Presenter::export($x), $this->exports->all());
    }

    /** `GET /exports/{id}/csv` */
    public function csv(string $id): string
    {
        $export = preg_match('/^CSV-\d{4,}$/', $id) === 1 ? $this->exports->find($id) : null;
        if ($export === null) {
            throw new Ap2NotFound('Export not found');
        }

        return $export->csv;
    }

    /**
     * `POST /exports`: `{invoiceIds[]}` -> the export with its `csv` text.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(Ap2Actor $actor, array $body): array
    {
        $raw = $body['invoiceIds'] ?? null;
        $ids = [];
        if (is_array($raw) && array_is_list($raw)) {
            foreach ($raw as $one) {
                if (is_string($one) && preg_match('/^[0-9a-f]{32}$/', $one) === 1) {
                    $ids[$one] = $one;
                } else {
                    $ids = [];
                    break;
                }
            }
        }
        if ($ids === [] || count($ids) > self::MAX_INVOICES) {
            throw new Ap2Invalid('Select invoices to export', Ap2Failure::details(['invoiceIds must be a list of one to ' . self::MAX_INVOICES . ' invoice ids']));
        }
        $ids = array_values($ids);

        $rows = [];
        $missing = [];
        foreach ($ids as $id) {
            $invoice = $this->invoices->find($id);
            if ($invoice === null) {
                $missing[] = $id . ' is not an invoice';
                continue;
            }
            foreach (self::rowsFor($invoice, $this->suppliers->find($invoice->supplierId)->name ?? '') as $row) {
                $rows[] = $row;
            }
        }
        if ($missing !== []) {
            throw new Ap2Invalid('Some invoices do not exist', Ap2Failure::details($missing));
        }

        $now = $this->time->now();

        return $this->transactions->run(function () use ($ids, $rows, $actor, $now): array {
            $export = new Ap2Export($this->exports->nextId(), $ids, self::HEADERS, $rows, Ap2Csv::build(self::HEADERS, $rows), $now, $actor->person());
            $this->exports->insert($export);
            foreach ($ids as $id) {
                $invoice = $this->invoices->find($id);
                if ($invoice === null) {
                    continue;
                }
                if (in_array($invoice->importState, ['Not exported', 'Import failed'], true)) {
                    $this->invoices->update($invoice->with(importState: 'Exported CSV', updatedAt: $now));
                }
                $this->history->append(new Ap2HistoryEntry(
                    bin2hex(random_bytes(16)),
                    $invoice->supplierId,
                    'Odoo CSV exported',
                    $invoice->number . ' · ' . $export->id . ' · exported without validation gate; not imported or posted',
                    $actor->person(),
                    $now,
                    $invoice->id,
                    null,
                    ['exportId' => $export->id],
                ));
            }

            return Ap2Presenter::export($export, true);
        });
    }

    /**
     * `POST /invoices/{id}/import-result`: `{result, reference, confirmed: true}`.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function importResult(Ap2Actor $actor, string $id, array $body): array
    {
        $invoice = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->invoices->find($id) : null;
        if ($invoice === null) {
            throw new Ap2NotFound('Invoice not found');
        }
        $input = new Ap2Input($body);
        $input->choice('result', self::RESULTS);
        $input->text('reference', 100, true);
        $issues = $input->issues();
        foreach (['result', 'reference'] as $key) {
            if (!$input->has($key)) {
                $issues[] = $key . ' is required';
            }
        }
        if (($body['confirmed'] ?? null) !== true) {
            $issues[] = 'confirmed must be true: confirm the outcome in Odoo before recording it';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The import result is invalid', Ap2Failure::details($issues));
        }
        $values = $input->values();
        $result = is_string($values['result'] ?? null) ? $values['result'] : '';
        $reference = is_string($values['reference'] ?? null) ? $values['reference'] : '';

        if (!in_array($invoice->importState, ['Exported CSV', 'Imported draft', 'Import failed'], true)) {
            throw new Ap2Conflict('Export the invoice before recording an import result', Ap2Failure::details(['The import state is ' . $invoice->importState]));
        }
        $supplier = $this->suppliers->find($invoice->supplierId);
        $a = $this->assessor->one($invoice, $supplier, $this->time->today());
        if ($result !== 'Import failed' && ($a->validation !== [] || !$a->approvalCurrent)) {
            throw new Ap2Conflict('Validation / approval is missing or stale', Ap2Failure::details([
                ...($a->validation === [] ? [] : ['Unresolved invoice checks: ' . implode('; ', $a->validation)]),
                ...($a->approvalCurrent ? [] : ['Invoice approval required / stale']),
            ]));
        }

        $now = $this->time->now();
        $changed = $invoice->with(importState: $result, odooRef: $reference, updatedAt: $now);
        $changed = $changed->with(stage: Ap2InvoiceRules::stage($changed, $a->validation));

        $this->transactions->run(function () use ($changed, $actor, $now, $result, $reference): void {
            $this->invoices->update($changed);
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $changed->supplierId,
                'Odoo result recorded',
                $changed->number . ': ' . $result . ' · ' . $reference,
                $actor->person(),
                $now,
                $changed->id,
            ));
        });

        return $this->present($changed);
    }

    /**
     * The template rows of one invoice (the prototype's `odooVendorRows`): the first line carries the bill columns,
     * the others leave them blank.
     *
     * @return list<list<string|float|null>>
     */
    private static function rowsFor(Ap2Invoice $b, string $vendor): array
    {
        $bill = [$b->odooExternalId(), $vendor, self::odooDate($b->issued), self::odooDate($b->due), $b->number, $b->journal, $b->currency];
        $rows = [];
        foreach ($b->odooLines() as $i => $line) {
            $rows[] = [
                ...($i === 0 ? $bill : array_fill(0, self::BILL_COLUMNS, '')),
                $line->label, $line->account, self::number($line->quantity), self::number($line->unitPrice), $line->taxes,
            ];
        }

        return $rows;
    }

    /** `yyyy-MM-dd` as the template's d/m/yyyy (`2026-10-01` → `1/10/2026`); empty when unknown. */
    private static function odooDate(?string $day): string
    {
        if ($day === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) !== 1) {
            return $day ?? '';
        }

        return (int) $m[3] . '/' . (int) $m[2] . '/' . $m[1];
    }

    /**
     * A quantity or unit price as the template writes it: `1`, `25`, `12.5`, `333.3333` (up to four places, no
     * trailing zeros). An int or a float keeps {@see Ap2Csv} from treating a negative as a formula.
     */
    private static function number(float $value): int|float
    {
        $rounded = round($value, 4);

        return floor($rounded) === $rounded && abs($rounded) < PHP_INT_MAX ? (int) $rounded : $rounded;
    }

    /** @return array<string, mixed> */
    private function present(Ap2Invoice $invoice): array
    {
        $supplier = $this->suppliers->find($invoice->supplierId);

        return Ap2Presenter::invoice($invoice, $supplier, $this->assessor->one($invoice, $supplier, $this->time->today()));
    }
}
