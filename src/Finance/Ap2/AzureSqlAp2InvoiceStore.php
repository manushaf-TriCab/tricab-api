<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2PoForecastRow;

/** `finance.ap2_invoices` (`create-finance-ap2-schema.sql`), with the live-payment and PO-estimate reads the rules need. */
final class AzureSqlAp2InvoiceStore extends AzureSqlAp2Support implements Ap2InvoiceStore
{
    private const TABLE = '[finance].[ap2_invoices]';

    private const COLUMNS = [
        'id', 'supplier_id', 'number', 'entity', 'currency', 'net', 'tax', 'total', 'issued', 'due', 'kind', 'po', 'quantity',
        'ordered_qty', 'received_qty', 'po_unit_price', 'receipt_ref', 'service_accepted', 'no_po_reason', 'gl', 'tax_code',
        'cost_centre', 'credit', 'credit_ref', 'credit_net', 'credit_tax', 'credit_resolved', 'paid', 'stage', 'verified',
        'import_state', 'odoo_ref', 'odoo_move_id', 'approval_id', 'excluded', 'excluded_reason', 'payment_hold', 'source_doc',
        'source_file_id', 'confidence', 'source_fingerprint', 'mail_id', 'owner_user_id', 'owner_name', 'owner_email',
        'created_at', 'updated_at',
    ];

    private const ORDER = ' ORDER BY [due], [number], [id]';

    public function all(): array
    {
        return array_map(self::invoice(...), $this->connection->fetchAll('SELECT ' . self::select() . ' FROM ' . self::TABLE . self::ORDER));
    }

    public function forSupplier(string $supplierId): array
    {
        return array_map(
            self::invoice(...),
            $this->connection->fetchAll('SELECT ' . self::select() . ' FROM ' . self::TABLE . ' WHERE [supplier_id] = :supplier' . self::ORDER, ['supplier' => $supplierId]),
        );
    }

    public function find(string $id): ?Ap2Invoice
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::select() . ' FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::invoice($rows[0]);
    }

    public function insert(Ap2Invoice $b): void
    {
        $names = array_map(static fn (string $c): string => '[' . $c . ']', self::COLUMNS);
        $marks = array_map(static fn (string $c): string => ':' . $c, self::COLUMNS);
        $this->write('INSERT INTO ' . self::TABLE . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $marks) . ')', self::params($b), 'The invoice already exists');
    }

    public function update(Ap2Invoice $b): void
    {
        $sets = [];
        foreach (self::COLUMNS as $column) {
            if ($column !== 'id' && $column !== 'created_at') {
                $sets[] = '[' . $column . '] = :' . $column;
            }
        }
        $params = self::params($b);
        unset($params['created_at']);
        $this->write('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE [id] = :id', $params, 'The invoice clashes with another');
    }

    public function hasActivePayment(string $invoiceId): bool
    {
        return $this->connection->fetchAll(
            'SELECT TOP (1) 1 AS [x] FROM [finance].[ap2_payments] WHERE [invoice_id] = :id AND [status] NOT IN (N\'Cancelled\', N\'Rejected\', N\'Paid\')',
            ['id' => $invoiceId],
        ) !== [];
    }

    public function invoiceIdsWithActivePayment(): array
    {
        $out = [];
        foreach ($this->connection->fetchAll('SELECT DISTINCT [invoice_id] FROM [finance].[ap2_payments] WHERE [status] NOT IN (N\'Cancelled\', N\'Rejected\', N\'Paid\')') as $row) {
            $out[self::id($row['invoice_id'])] = true;
        }

        return $out;
    }

    public function countCreated(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [n] FROM ' . self::TABLE . ' WHERE [created_at] >= :from AND [created_at] < :to',
            ['from' => self::at($from), 'to' => self::at($toExclusive)],
        );

        return self::int($rows[0]['n'] ?? 0);
    }

    public function poForecast(): array
    {
        return array_map(static fn (array $row): Ap2PoForecastRow => new Ap2PoForecastRow(
            self::text($row['po']),
            self::id($row['supplier_id']),
            self::amount($row['amount']),
            self::text($row['currency']),
            self::day($row['date']) ?? '',
        ), $this->connection->fetchAll('SELECT [po], [supplier_id], [amount], [currency], [date] FROM [finance].[ap2_po_forecast] ORDER BY [date], [po]'));
    }

    private static function select(): string
    {
        return implode(', ', array_map(static fn (string $c): string => '[' . $c . ']', self::COLUMNS));
    }

    /** @return array<string, scalar|null> */
    private static function params(Ap2Invoice $b): array
    {
        return [
            'id' => $b->id,
            'supplier_id' => $b->supplierId,
            'number' => $b->number,
            'entity' => $b->entity,
            'currency' => $b->currency,
            'net' => self::money($b->net),
            'tax' => self::money($b->tax),
            'total' => self::money($b->total),
            'issued' => $b->issued,
            'due' => $b->due,
            'kind' => $b->kind,
            'po' => $b->po,
            'quantity' => self::quantity($b->quantity),
            'ordered_qty' => self::quantity($b->orderedQty),
            'received_qty' => self::quantity($b->receivedQty),
            'po_unit_price' => self::quantity($b->poUnitPrice),
            'receipt_ref' => $b->receiptRef,
            'service_accepted' => $b->serviceAccepted ? 1 : 0,
            'no_po_reason' => $b->noPoReason,
            'gl' => $b->gl,
            'tax_code' => $b->taxCode,
            'cost_centre' => $b->costCentre,
            'credit' => self::money($b->credit),
            'credit_ref' => $b->creditRef,
            'credit_net' => self::money($b->creditNet),
            'credit_tax' => self::money($b->creditTax),
            'credit_resolved' => $b->creditResolved ? 1 : 0,
            'paid' => self::money($b->paid),
            'stage' => $b->stage,
            'verified' => $b->verified ? 1 : 0,
            'import_state' => $b->importState,
            'odoo_ref' => $b->odooRef,
            'odoo_move_id' => $b->odooMoveId,
            'approval_id' => $b->approvalId,
            'excluded' => $b->excluded ? 1 : 0,
            'excluded_reason' => $b->excludedReason,
            'payment_hold' => $b->paymentHold ? 1 : 0,
            'source_doc' => $b->sourceDoc,
            'source_file_id' => $b->sourceFileId,
            'confidence' => $b->confidence === null ? null : number_format($b->confidence, 8, '.', ''),
            'source_fingerprint' => $b->sourceFingerprint,
            'mail_id' => $b->mailId,
            'owner_user_id' => $b->owner?->userId,
            'owner_name' => $b->owner->name ?? '',
            'owner_email' => $b->owner->email ?? '',
            'created_at' => self::at($b->createdAt),
            'updated_at' => self::at($b->updatedAt),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function invoice(array $row): Ap2Invoice
    {
        return new Ap2Invoice(
            self::id($row['id']),
            self::id($row['supplier_id']),
            self::when($row['created_at'], 'created_at'),
            self::when($row['updated_at'], 'updated_at'),
            number: self::text($row['number']),
            entity: self::text($row['entity']),
            currency: trim(self::text($row['currency'])),
            net: self::amount($row['net']),
            tax: self::amount($row['tax']),
            total: self::amount($row['total']),
            issued: self::day($row['issued']),
            due: self::day($row['due']),
            kind: self::text($row['kind']),
            po: self::text($row['po']),
            quantity: self::amount($row['quantity']),
            orderedQty: self::amount($row['ordered_qty']),
            receivedQty: self::amount($row['received_qty']),
            poUnitPrice: self::amount($row['po_unit_price']),
            receiptRef: self::text($row['receipt_ref']),
            serviceAccepted: self::bool($row['service_accepted']),
            noPoReason: self::text($row['no_po_reason']),
            gl: self::text($row['gl']),
            taxCode: self::text($row['tax_code']),
            costCentre: self::text($row['cost_centre']),
            credit: self::amount($row['credit']),
            creditRef: self::text($row['credit_ref']),
            creditNet: self::amount($row['credit_net']),
            creditTax: self::amount($row['credit_tax']),
            creditResolved: self::bool($row['credit_resolved']),
            paid: self::amount($row['paid']),
            stage: self::text($row['stage']),
            verified: self::bool($row['verified']),
            importState: self::text($row['import_state']),
            odooRef: self::text($row['odoo_ref']),
            odooMoveId: AzureSqlValueMapper::nullableInt($row['odoo_move_id']),
            approvalId: self::textOrNull($row['approval_id']),
            excluded: self::bool($row['excluded']),
            excludedReason: self::text($row['excluded_reason']),
            paymentHold: self::bool($row['payment_hold']),
            sourceDoc: self::text($row['source_doc']),
            sourceFileId: self::textOrNull($row['source_file_id']),
            confidence: $row['confidence'] === null ? null : self::amount($row['confidence']),
            sourceFingerprint: self::text($row['source_fingerprint']),
            mailId: self::textOrNull($row['mail_id']),
            owner: self::person($row, 'owner'),
        );
    }
}
