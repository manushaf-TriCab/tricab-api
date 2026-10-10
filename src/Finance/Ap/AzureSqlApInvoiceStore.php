<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use LogicException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceQuery;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;

/**
 * The register in `finance.invoice_invoices` and what hangs off it (`create-finance-invoice-schema.sql`).
 *
 * The derived Overdue is a CASE expression in the `WHERE` and the `ORDER BY`: the stored column never holds it (question Q4),
 * so the `status` filter and sort agree with the rows. The mailbox scope, the period and the limit are all applied in the
 * one query. Every list sorts on a bounded column and then on the id. `sender_name` is found from `invoice_emails` by the
 * invoice's `email_record_id`, else by its Graph message id.
 */
final class AzureSqlApInvoiceStore extends AzureSqlApSupport implements ApInvoiceStore
{
    private const TABLE = '[finance].[invoice_invoices]';

    private const COLUMNS = [
        'id', 'email_id', 'mailbox', 'email_date', 'sender', 'subject', 'email_record_id', 'attachment_id', 'attachment', 'file_id',
        'content_hash', 'invoice_no', 'vendor', 'vendor_email', 'tax_id', 'invoice_date', 'due_date', 'po_number', 'currency',
        'net_amount', 'tax_amount', 'total_amount', 'company', 'account_category', 'cost_centre', 'status', 'notes', 'is_revised',
        'duplicate_status', 'duplicate_of_invoice_id', 'duplicate_reason', 'approval_status', 'extraction_confidence', 'review_reason',
        'deleted_at', 'deleted_by_user_id', 'deleted_by', 'odoo_vendor_id', 'vendor_ref', 'odoo_vendor_name', 'vendor_match',
        'vendor_match_note', 'vendor_match_by_user_id', 'created_date', 'updated_date',
    ];

    private const MONEY = ['net_amount', 'tax_amount', 'total_amount'];

    /** The column each sort key orders by (`status` is built per query, from the derived status). */
    private const SORT_COLUMNS = [
        'id' => 'i.[id]', 'created_date' => 'i.[created_date]', 'updated_date' => 'i.[updated_date]', 'email_date' => 'i.[email_date]',
        'invoice_date' => 'i.[invoice_date]', 'due_date' => 'i.[due_date]', 'deleted_at' => 'i.[deleted_at]', 'vendor' => 'i.[vendor]',
        'invoice_no' => 'i.[invoice_no]', 'mailbox' => 'i.[mailbox]', 'company' => 'i.[company]', 'currency' => 'i.[currency]',
        'net_amount' => 'i.[net_amount]', 'tax_amount' => 'i.[tax_amount]', 'total_amount' => 'i.[total_amount]',
        'cost_centre' => 'i.[cost_centre]', 'account_category' => 'i.[account_category]', 'approval_status' => 'i.[approval_status]',
        'duplicate_status' => 'i.[duplicate_status]', 'vendor_match' => 'i.[vendor_match]', 'odoo_vendor_name' => 'i.[odoo_vendor_name]',
        'po_number' => 'i.[po_number]', 'tax_id' => 'i.[tax_id]', 'vendor_email' => 'i.[vendor_email]', 'sender' => 'i.[sender]',
        'subject' => 'i.[subject]', 'attachment' => 'i.[attachment]',
    ];

    public function page(ApInvoiceQuery $query): array
    {
        [$where, $params] = $this->where($query);
        $key = $query->sortBy === 'status' ? self::effectiveStatus($query->today) : (self::SORT_COLUMNS[$query->sortBy] ?? 'i.[created_date]');
        $direction = $query->descending ? 'DESC' : 'ASC';
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, min(ApInvoiceQuery::MAX_LIMIT, $query->limit)) . ') ' . self::select() . self::FROM
            . ' WHERE ' . $where . ' ORDER BY ' . $key . ' ' . $direction . ($query->sortBy === 'id' ? '' : ', i.[id] ' . $direction),
            $params,
        );

        return array_map(self::invoice(...), $rows);
    }

    public function find(string $id): ?ApInvoice
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::select() . self::FROM . ' WHERE i.[id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::invoice($rows[0]);
    }

    public function findMany(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            [$marks, $params] = self::in('id', $chunk);
            foreach ($this->connection->fetchAll('SELECT ' . self::select() . self::FROM . ' WHERE i.[id] IN (' . $marks . ')', $params) as $row) {
                $invoice = self::invoice($row);
                $out[$invoice->id] = $invoice;
            }
        }

        return $out;
    }

    public function exists(string $id): bool
    {
        return $this->connection->fetchAll('SELECT 1 AS [x] FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]) !== [];
    }

    public function insert(ApInvoice $invoice): void
    {
        $columns = self::COLUMNS;
        $names = array_map(static fn (string $c): string => '[' . $c . ']', $columns);
        $marks = array_map(static fn (string $c): string => ':' . $c, $columns);
        $this->connection->execute(
            'INSERT INTO ' . self::TABLE . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $marks) . ')',
            [
                'id' => $invoice->id,
                'email_id' => $invoice->emailId,
                'mailbox' => $invoice->mailbox,
                'email_date' => self::atOrNull($invoice->emailDate),
                'sender' => $invoice->sender,
                'subject' => $invoice->subject,
                'email_record_id' => $invoice->emailRecordId,
                'attachment_id' => $invoice->attachmentId,
                'attachment' => $invoice->attachment,
                'file_id' => $invoice->fileId,
                'content_hash' => $invoice->contentHash,
                'invoice_no' => $invoice->invoiceNo,
                'vendor' => $invoice->vendor,
                'vendor_email' => $invoice->vendorEmail,
                'tax_id' => $invoice->taxId,
                'invoice_date' => $invoice->invoiceDate,
                'due_date' => $invoice->dueDate,
                'po_number' => $invoice->poNumber,
                'currency' => $invoice->currency,
                'net_amount' => self::money($invoice->netAmount),
                'tax_amount' => self::money($invoice->taxAmount),
                'total_amount' => self::money($invoice->totalAmount),
                'company' => $invoice->company,
                'account_category' => $invoice->accountCategory,
                'cost_centre' => $invoice->costCentre,
                'status' => $invoice->status,
                'notes' => $invoice->notes,
                'is_revised' => $invoice->isRevised ? 1 : 0,
                'duplicate_status' => $invoice->duplicateStatus,
                'duplicate_of_invoice_id' => $invoice->duplicateOfInvoiceId,
                'duplicate_reason' => $invoice->duplicateReason,
                'approval_status' => $invoice->approvalStatus,
                'extraction_confidence' => $invoice->extractionConfidence === null ? null : number_format($invoice->extractionConfidence, 8, '.', ''),
                'review_reason' => $invoice->reviewReason,
                'deleted_at' => self::atOrNull($invoice->deletedAt),
                'deleted_by_user_id' => $invoice->deletedByUserId,
                'deleted_by' => $invoice->deletedBy,
                'odoo_vendor_id' => $invoice->odooVendorId,
                'vendor_ref' => $invoice->vendorRef,
                'odoo_vendor_name' => $invoice->odooVendorName,
                'vendor_match' => $invoice->vendorMatch,
                'vendor_match_note' => $invoice->vendorMatchNote,
                'vendor_match_by_user_id' => $invoice->vendorMatchByUserId,
                'created_date' => self::at($invoice->createdAt),
                'updated_date' => self::at($invoice->updatedAt),
            ],
        );
    }

    public function update(string $id, array $columns, DateTimeImmutable $at): void
    {
        $unknown = array_diff(array_keys($columns), ApInvoice::PATCHABLE);
        if ($unknown !== []) {
            throw new LogicException('Not an editable column: ' . implode(', ', $unknown));
        }
        $sets = ['[updated_date] = :updated'];
        $params = ['id' => $id, 'updated' => self::at($at)];
        foreach ($columns as $column => $value) {
            $sets[] = '[' . $column . '] = :c_' . $column;
            $params['c_' . $column] = match (true) {
                in_array($column, self::MONEY, true) => self::money(is_float($value) || is_int($value) ? (float) $value : null),
                $column === 'is_revised' => $value === true ? 1 : 0,
                is_bool($value) => $value ? 1 : 0,
                is_int($value), is_float($value) => $value,
                is_string($value) => $value,
                default => null,
            };
        }
        $this->connection->execute('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE [id] = :id', $params);
    }

    public function bin(string $id, ?int $byUserId, string $by, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . ' SET [deleted_at] = :at, [deleted_by_user_id] = :userId, [deleted_by] = :by, [updated_date] = :updated
              WHERE [id] = :id AND [deleted_at] IS NULL',
            ['at' => self::at($at), 'userId' => $byUserId, 'by' => $by, 'updated' => self::at($at), 'id' => $id],
        );
    }

    public function restore(string $id, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . ' SET [deleted_at] = NULL, [deleted_by_user_id] = NULL, [deleted_by] = N\'\', [updated_date] = :updated WHERE [id] = :id',
            ['updated' => self::at($at), 'id' => $id],
        );
    }

    public function purge(string $id, ?string $emailId, ?string $skipKey, DateTimeImmutable $at): void
    {
        $this->transactional(function () use ($id, $emailId, $skipKey, $at): void {
            // Children first: the keys have no cascade.
            $this->connection->execute('DELETE FROM [finance].[invoice_approval_items] WHERE [invoice_id] = :id', ['id' => $id]);
            $this->connection->execute('DELETE FROM [finance].[invoice_outbound_messages] WHERE [invoice_id] = :id', ['id' => $id]);
            $this->connection->execute(
                'UPDATE [finance].[invoice_email_attachments] SET [file_id] = NULL
                  WHERE [file_id] IS NOT NULL AND [file_id] = (SELECT [file_id] FROM ' . self::TABLE . ' WHERE [id] = :id)',
                ['id' => $id],
            );
            $this->connection->execute('UPDATE [finance].[invoice_email_attachments] SET [invoice_id] = NULL WHERE [invoice_id] = :id', ['id' => $id]);
            $this->connection->execute('UPDATE [finance].[invoice_email_attachments] SET [duplicate_of_invoice_id] = NULL WHERE [duplicate_of_invoice_id] = :id', ['id' => $id]);
            $this->connection->execute('UPDATE ' . self::TABLE . ' SET [duplicate_of_invoice_id] = NULL WHERE [duplicate_of_invoice_id] = :id', ['id' => $id]);
            if ($emailId !== null && $skipKey !== null) {
                $this->tombstone($emailId, $skipKey, $at);
            }
            $this->connection->execute('DELETE FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);
        });
    }

    public function findByContentHash(string $hash): ?ApInvoice
    {
        if ($hash === '') {
            return null;
        }
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) ' . self::select() . self::FROM . ' WHERE i.[content_hash] = :hash ORDER BY i.[created_date], i.[id]',
            ['hash' => $hash],
        );

        return $rows === [] ? null : self::invoice($rows[0]);
    }

    public function findBusinessDuplicate(string $invoiceNo, string $vendor, string $currency, float $total): ?ApInvoice
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) ' . self::select() . self::FROM . '
              WHERE LOWER(LTRIM(RTRIM(i.[invoice_no]))) = :invoiceNo AND LOWER(LTRIM(RTRIM(i.[vendor]))) = :vendor
                AND UPPER(COALESCE(NULLIF(i.[currency], N\'\'), N\'AUD\')) = :currency
                AND ABS(COALESCE(i.[total_amount], 0) - CAST(:total AS DECIMAL(18,2))) < 0.01
              ORDER BY i.[created_date], i.[id]',
            ['invoiceNo' => mb_strtolower($invoiceNo), 'vendor' => mb_strtolower($vendor), 'currency' => strtoupper($currency), 'total' => self::money($total)],
        );

        return $rows === [] ? null : self::invoice($rows[0]);
    }

    public function findCaptured(string $emailId, ?string $attachmentId, string $name): ?ApInvoice
    {
        if ($attachmentId !== null && $attachmentId !== '') {
            $rows = $this->connection->fetchAll(
                'SELECT TOP (1) ' . self::select() . self::FROM . ' WHERE i.[email_id] = :email AND i.[attachment_id] = :attachment ORDER BY i.[created_date], i.[id]',
                ['email' => $emailId, 'attachment' => $attachmentId],
            );
            if ($rows !== []) {
                return self::invoice($rows[0]);
            }
        }
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) ' . self::select() . self::FROM . '
              WHERE i.[email_id] = :email AND i.[attachment] = :name AND (i.[attachment_id] IS NULL OR i.[attachment_id] = N\'\')
              ORDER BY i.[created_date], i.[id]',
            ['email' => $emailId, 'name' => $name],
        );

        return $rows === [] ? null : self::invoice($rows[0]);
    }

    public function scanSkipped(string $emailId, ?string $attachmentId, string $name): bool
    {
        $keys = array_values(array_filter([$attachmentId, $name], static fn (?string $key): bool => $key !== null && $key !== ''));
        if ($keys === []) {
            return false;
        }
        [$marks, $params] = self::in('key', $keys);

        return $this->connection->fetchAll(
            'SELECT 1 AS [x] FROM [finance].[invoice_scan_skips] WHERE [email_id] = :email AND [attachment] IN (' . $marks . ')',
            ['email' => $emailId] + $params,
        ) !== [];
    }

    public function invoiceIdsByEmail(array $emailRecordIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($emailRecordIds)), 500) as $chunk) {
            [$marks, $params] = self::in('email', $chunk);
            $rows = $this->connection->fetchAll(
                'SELECT [id], [email_record_id] FROM ' . self::TABLE . ' WHERE [email_record_id] IN (' . $marks . ') ORDER BY [created_date], [id]',
                $params,
            );
            foreach ($rows as $row) {
                $out[self::id($row['email_record_id'])][] = self::id($row['id']);
            }
        }

        return $out;
    }

    public function setVendorMatch(string $id, ApVendorMatch $match, ?int $byUserId, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . ' SET [odoo_vendor_id] = :vendorId, [vendor_ref] = :ref, [odoo_vendor_name] = :name, [vendor_match] = :match,
                    [vendor_match_note] = :note, [vendor_match_by_user_id] = :by, [updated_date] = :updated
              WHERE [id] = :id',
            [
                'vendorId' => $match->odooVendorId,
                'ref' => $match->vendorRef ?? '',
                'name' => $match->odooVendorName ?? '',
                'match' => $match->vendorMatch,
                'note' => $match->vendorMatchNote,
                'by' => $byUserId,
                'updated' => self::at($at),
                'id' => $id,
            ],
        );
    }

    public function liveCountsByVendor(): array
    {
        $counts = [];
        foreach ($this->connection->fetchAll(
            'SELECT [odoo_vendor_id] AS [vendor], COUNT(*) AS [n] FROM ' . self::TABLE . ' WHERE [odoo_vendor_id] IS NOT NULL AND [deleted_at] IS NULL GROUP BY [odoo_vendor_id]',
        ) as $row) {
            $counts[self::int($row['vendor'])] = self::int($row['n']);
        }

        return $counts;
    }

    public function setApprovalStatus(array $ids, string $status, DateTimeImmutable $at): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            [$marks, $params] = self::in('id', $chunk);
            $this->connection->execute(
                'UPDATE ' . self::TABLE . ' SET [approval_status] = :status, [updated_date] = :updated WHERE [id] IN (' . $marks . ')',
                ['status' => $status, 'updated' => self::at($at)] + $params,
            );
        }
    }

    // ---- the query ----------------------------------------------------------------------

    private const FROM = ' FROM [finance].[invoice_invoices] i
        LEFT JOIN [finance].[invoice_emails] er ON er.[id] = i.[email_record_id]
        OUTER APPLY (SELECT TOP (1) em.[sender_name] FROM [finance].[invoice_emails] em
                      WHERE em.[graph_message_id] = i.[email_id] AND em.[sender_name] <> N\'\'
                      ORDER BY em.[created_date], em.[id]) m';

    /**
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function where(ApInvoiceQuery $q): array
    {
        $where = [$q->deleted ? 'i.[deleted_at] IS NOT NULL' : 'i.[deleted_at] IS NULL'];
        $params = [];
        if ($q->start !== null) {
            $where[] = 'COALESCE(i.[email_date], i.[created_date]) >= :start';
            $params['start'] = self::at($q->start);
        }
        if ($q->end !== null) {
            $where[] = 'COALESCE(i.[email_date], i.[created_date]) < :end';
            $params['end'] = self::at($q->end);
        }
        if ($q->mailboxes !== null) {
            [$marks, $in] = self::in('mailbox', $q->mailboxes);
            $where[] = '(i.[mailbox] IS NULL OR LTRIM(RTRIM(i.[mailbox])) = N\'\' OR LOWER(LTRIM(RTRIM(i.[mailbox]))) IN (' . $marks . '))';
            $params += $in;
        }
        if ($q->id !== null) {
            $where[] = 'i.[id] = :id';
            $params['id'] = $q->id;
        }
        if ($q->status !== null) {
            $where[] = self::effectiveStatus($q->today) . ' = :status';
            $params['status'] = $q->status;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * The derived status as SQL (question Q4): a live invoice stored Needs Review or In Progress whose due date is before
     * `$today` is Overdue. `$today` is the clock port's date, checked here to be a date, never a request's text.
     */
    private static function effectiveStatus(string $today): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new LogicException('The date overdue is judged against must be yyyy-MM-dd.');
        }

        return "(CASE WHEN i.[deleted_at] IS NULL AND i.[status] IN (N'Needs Review', N'In Progress') AND i.[due_date] IS NOT NULL AND i.[due_date] < CAST('" . $today . "' AS DATE) THEN N'Overdue' ELSE i.[status] END)";
    }

    private static function select(): string
    {
        $columns = array_map(static fn (string $c): string => 'i.[' . $c . ']', self::COLUMNS);

        return implode(', ', $columns) . ", COALESCE(NULLIF(er.[sender_name], N''), m.[sender_name], N'') AS [sender_display]";
    }

    /**
     * Numbered parameters for an `IN (...)` list.
     *
     * @param list<string> $values
     * @return array{0: string, 1: array<string, string>}
     */
    private static function in(string $name, array $values): array
    {
        $marks = [];
        $params = [];
        foreach ($values as $n => $value) {
            $marks[] = ':' . $name . $n;
            $params[$name . $n] = $value;
        }

        return [implode(', ', $marks), $params];
    }

    private function tombstone(string $emailId, string $key, DateTimeImmutable $at): void
    {
        try {
            $this->write(
                'IF NOT EXISTS (SELECT 1 FROM [finance].[invoice_scan_skips] WHERE [email_id] = :email AND [attachment] = :attachment)
                 INSERT INTO [finance].[invoice_scan_skips] ([id], [email_id], [attachment], [created_date]) VALUES (:id, :email2, :attachment2, :at)',
                ['email' => $emailId, 'attachment' => $key, 'id' => bin2hex(random_bytes(16)), 'email2' => $emailId, 'attachment2' => $key, 'at' => self::at($at)],
                'That attachment is already skipped',
            );
        } catch (ApConflict) {
            // Another delete wrote the same tombstone first: the pair is unique, and it is there.
        }
    }

    /** An amount as the `DECIMAL(18,2)` column takes it, exact to the cent. */
    private static function money(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, '.', '');
    }

    private static function amount(mixed $value): ?float
    {
        return $value === null ? null : (float) (is_scalar($value) ? $value : 0);
    }

    private static function day(mixed $value): ?string
    {
        $text = self::textOrNull($value);

        return $text === null || $text === '' ? null : substr($text, 0, 10);
    }

    /** @param array<string, mixed> $row */
    private static function invoice(array $row): ApInvoice
    {
        return new ApInvoice(
            self::id($row['id']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
            emailId: self::textOrNull($row['email_id']),
            mailbox: self::textOrNull($row['mailbox']),
            emailDate: self::whenOrNull($row['email_date']),
            sender: self::text($row['sender']),
            subject: self::text($row['subject']),
            emailRecordId: self::textOrNull($row['email_record_id']),
            attachmentId: self::textOrNull($row['attachment_id']),
            attachment: self::text($row['attachment']),
            fileId: self::textOrNull($row['file_id']),
            contentHash: self::textOrNull($row['content_hash']),
            invoiceNo: self::text($row['invoice_no']),
            vendor: self::text($row['vendor']),
            vendorEmail: self::text($row['vendor_email']),
            taxId: self::text($row['tax_id']),
            invoiceDate: self::day($row['invoice_date']),
            dueDate: self::day($row['due_date']),
            poNumber: self::text($row['po_number']),
            currency: self::text($row['currency']),
            netAmount: self::amount($row['net_amount']),
            taxAmount: self::amount($row['tax_amount']),
            totalAmount: self::amount($row['total_amount']),
            company: self::text($row['company']),
            accountCategory: self::text($row['account_category']),
            costCentre: self::text($row['cost_centre']),
            status: self::text($row['status']),
            notes: self::textOrNull($row['notes']),
            isRevised: self::bool($row['is_revised']),
            duplicateStatus: self::text($row['duplicate_status']),
            duplicateOfInvoiceId: self::textOrNull($row['duplicate_of_invoice_id']),
            duplicateReason: self::text($row['duplicate_reason']),
            approvalStatus: self::text($row['approval_status']),
            extractionConfidence: self::amount($row['extraction_confidence']),
            reviewReason: self::textOrNull($row['review_reason']),
            deletedAt: self::whenOrNull($row['deleted_at']),
            deletedByUserId: AzureSqlValueMapper::nullableInt($row['deleted_by_user_id']),
            deletedBy: self::text($row['deleted_by']),
            odooVendorId: AzureSqlValueMapper::nullableInt($row['odoo_vendor_id']),
            vendorRef: self::text($row['vendor_ref']),
            odooVendorName: self::text($row['odoo_vendor_name']),
            vendorMatch: self::textOrNull($row['vendor_match']),
            vendorMatchNote: self::text($row['vendor_match_note']),
            vendorMatchByUserId: AzureSqlValueMapper::nullableInt($row['vendor_match_by_user_id']),
            senderName: self::text($row['sender_display']),
        );
    }
}
