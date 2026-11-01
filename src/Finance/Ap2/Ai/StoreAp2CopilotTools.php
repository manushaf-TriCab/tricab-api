<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2CopilotTools;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;
use TriCab\Core\Finance\Ap2\Service\Ap2Assessor;
use TriCab\Core\Finance\Ap2\Service\Ap2Fx;
use TriCab\Core\Finance\Ap2\Service\Ap2Positions;
use TriCab\Core\Finance\Ap2\Service\Ap2Presenter;

/**
 * The Copilot's lookups, read through the AP2 stores and shaped by the same {@see Ap2Assessor} and
 * {@see Ap2Presenter} the routes use, so the rules, AUD balances and queues are the server's, not re-derived here.
 * Only reads are called; nothing here writes.
 *
 * Records are trimmed to what an answer needs, which keeps each tool result small.
 */
final class StoreAp2CopilotTools implements Ap2CopilotTools
{
    private const INVOICE_FIELDS = [
        'id', 'number', 'entity', 'currency', 'net', 'tax', 'total', 'issued', 'due', 'kind', 'po', 'quantity', 'receivedQty',
        'credit', 'creditRef', 'paid', 'stage', 'verified', 'importState', 'odooRef', 'paymentHold', 'excluded', 'mailId',
        'validation', 'matchStatus', 'duplicateOf', 'queue', 'approvalCurrent', 'businessApproval', 'postingIssues', 'paymentIssues', 'balance', 'locked', 'daysOverdue',
    ];

    private const LIST_FIELDS = ['id', 'number', 'currency', 'total', 'balance', 'due', 'stage', 'queue', 'importState', 'daysOverdue'];

    private const MAX_FOUND = 10;

    public function __construct(
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2Assessor $assessor,
        private readonly ApTime $time,
    ) {
    }

    public function invoices(string $supplierId): array
    {
        $supplier = $this->suppliers->find($supplierId);
        if ($supplier === null) {
            return self::notFound('supplier', $supplierId);
        }
        $rows = $this->presented($supplier);

        return ['found' => true, 'supplierId' => $supplierId, 'count' => count($rows), 'invoices' => array_map(static fn (array $r): array => self::only($r, self::LIST_FIELDS), $rows)];
    }

    public function invoice(?string $invoiceId, ?string $supplierId, ?string $number): array
    {
        if ($invoiceId !== null) {
            $invoice = $this->invoices->find($invoiceId);
            $supplier = $invoice === null ? null : $this->suppliers->find($invoice->supplierId);
            if ($invoice === null) {
                return self::notFound('invoice', $invoiceId);
            }

            return ['found' => true, 'invoice' => self::only($this->present($invoice, $supplier), self::INVOICE_FIELDS)];
        }
        if ($supplierId === null || $number === null || trim($number) === '') {
            return ['found' => false, 'error' => 'Give an invoice id, or a supplier id and an invoice number.'];
        }
        $supplier = $this->suppliers->find($supplierId);
        if ($supplier === null) {
            return self::notFound('supplier', $supplierId);
        }
        $wanted = self::normalised($number);
        foreach ($this->presented($supplier) as $row) {
            if (is_string($row['number'] ?? null) && self::normalised($row['number']) === $wanted) {
                return ['found' => true, 'invoice' => self::only($row, self::INVOICE_FIELDS)];
            }
        }

        return ['found' => false, 'error' => 'No invoice numbered ' . $number . ' for this supplier.'];
    }

    public function exceptions(string $supplierId): array
    {
        $supplier = $this->suppliers->find($supplierId);
        if ($supplier === null) {
            return self::notFound('supplier', $supplierId);
        }
        $out = [];
        foreach ($this->presented($supplier) as $row) {
            $validation = is_array($row['validation'] ?? null) ? $row['validation'] : [];
            $out[] = [
                'id' => $row['id'] ?? null,
                'number' => $row['number'] ?? null,
                'stage' => $row['stage'] ?? null,
                'validation' => $validation,
                'paymentIssues' => is_array($row['paymentIssues'] ?? null) ? $row['paymentIssues'] : [],
                'needsVerification' => $validation !== [],
            ];
        }

        return ['found' => true, 'supplierId' => $supplierId, 'invoices' => $out];
    }

    public function supplierSummary(string $supplierId): array
    {
        $supplier = $this->suppliers->find($supplierId);
        if ($supplier === null) {
            return self::notFound('supplier', $supplierId);
        }
        $all = $this->invoices->forSupplier($supplierId);
        $position = Ap2Positions::bySupplier($all, new Ap2Fx($this->settings->get()->fxRates), $this->time->today())[$supplierId]
            ?? ['balance' => 0.0, 'overdue' => 0.0, 'openInvoices' => 0];
        $s = Ap2Presenter::supplier($supplier, $position);

        return [
            'found' => true,
            'supplier' => self::only($s, ['id', 'name', 'email', 'contact', 'entity', 'terms', 'owner', 'bankVerified', 'bankChange', 'hold', 'notes']),
            'invoiceCount' => count(array_filter($all, static fn (Ap2Invoice $b): bool => !$b->excluded)),
            'postedOpenApAud' => $s['balance'],
            'overduePostedApAud' => $s['overdue'],
            'openPostedInvoices' => $s['openInvoices'],
            'paymentHold' => $supplier->hold || !$supplier->bankVerified ? 'Yes / verification required' : 'No supplier hold',
        ];
    }

    public function history(string $supplierId, int $limit): array
    {
        if ($this->suppliers->find($supplierId) === null) {
            return self::notFound('supplier', $supplierId);
        }
        $rows = $this->history->forSupplier($supplierId);

        return [
            'found' => true,
            'supplierId' => $supplierId,
            'total' => count($rows),
            'entries' => array_map(
                static fn ($e): array => ['type' => $e->type, 'detail' => $e->detail, 'at' => Ap2Presenter::time($e->at), 'by' => $e->actor->name, 'invoiceId' => $e->invoiceId],
                array_slice($rows, 0, max(1, min(30, $limit))),
            ),
        ];
    }

    public function findSuppliers(string $query): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return ['found' => false, 'error' => 'Give part of the supplier name.'];
        }
        $matches = [];
        foreach ($this->suppliers->all() as $s) {
            if (str_contains(mb_strtolower($s->name), $q) || ($s->email !== '' && str_contains(mb_strtolower($s->email), $q))) {
                $matches[] = ['id' => $s->id, 'name' => $s->name, 'entity' => $s->entity, 'email' => $s->email];
            }
        }

        return ['found' => $matches !== [], 'count' => count($matches), 'suppliers' => array_slice($matches, 0, self::MAX_FOUND)];
    }

    /**
     * The supplier's invoices as the API presents them, excluded duplicates left out (as the prototype's Copilot does).
     *
     * @return list<array<string, mixed>>
     */
    private function presented(Ap2Supplier $supplier): array
    {
        $rows = $this->invoices->forSupplier($supplier->id);
        $assessments = $this->assessor->many($rows, [$supplier->id => $supplier], $this->time->today());
        $out = [];
        foreach ($rows as $invoice) {
            if (!$invoice->excluded) {
                $out[] = Ap2Presenter::invoice($invoice, $supplier, $assessments[$invoice->id]);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function present(Ap2Invoice $invoice, ?Ap2Supplier $supplier): array
    {
        return Ap2Presenter::invoice($invoice, $supplier, $this->assessor->one($invoice, $supplier, $this->time->today()));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $fields
     * @return array<string, mixed>
     */
    private static function only(array $row, array $fields): array
    {
        return array_intersect_key($row, array_flip($fields));
    }

    private static function normalised(string $number): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($number));
    }

    /** @return array{found: false, error: string} */
    private static function notFound(string $what, string $id): array
    {
        return ['found' => false, 'error' => 'No ' . $what . ' with id ' . $id . '.'];
    }
}
