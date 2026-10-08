<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use DateTimeImmutable;
use LogicException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArExtraction;
use TriCab\Core\Finance\Ar\Domain\ArKeptAttachment;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArRecordQuery;
use TriCab\Core\Finance\Ar\Service\ArPresenter;

/**
 * The register in `finance.ar_email_records` and its children (`create-finance-ar-schema.sql`).
 *
 * The derived "overdue" is a CASE expression in the `WHERE`, the `GROUP BY` and the `ORDER BY`: the stored
 * column never holds it (question Q8). Every list sorts on a bounded column and then on the id; the
 * search is a case-insensitive "contains" with `!` as the LIKE escape, so `%` and `_` in the text match
 * themselves; the filter is applied before the page (`OFFSET ... FETCH`).
 */
final class AzureSqlArRecordStore extends AzureSqlArSupport implements ArRecordStore
{
    private const TABLE = '[finance].[ar_email_records]';

    private const COLUMNS = '[id], [email_date], [customer_name], [sender_email], [sender_name], [subject], [ai_summary], [follow_up_category],
        [assigned_user_id], [assigned_name], [proposed_response_type], [proposed_response], [follow_up_status], [due_date], [final_outcome],
        [source_message_id], [message_unreachable], [mailbox], [classification_confidence], [status], [assigned_sales_rep_email],
        [credit_note_request_id], [reminder_sent_at], [deleted_at], [deleted_by_user_id], [deleted_by], [created_date], [updated_date]';

    /** The column each sort key orders by (the derived status is built per query). */
    private const SORT_COLUMNS = [
        'email_date' => '[email_date]',
        'customer_name' => '[customer_name]',
        'sender_email' => '[sender_email]',
        'subject' => '[subject]',
        'follow_up_category' => '[follow_up_category]',
        'assigned_person' => '[assigned_name]',
        'proposed_response_type' => '[proposed_response_type]',
        'due_date' => '[due_date]',
        'classification_confidence' => '[classification_confidence]',
        'status' => '[status]',
        'created_date' => '[created_date]',
        'deleted_at' => '[deleted_at]',
    ];

    public function page(ArRecordQuery $query): array
    {
        [$where, $params] = $this->where($query);
        $count = $this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM ' . self::TABLE . ' WHERE ' . $where, $params);
        $total = self::int($count[0]['n'] ?? 0);

        $key = $query->sortBy === 'follow_up_status' ? self::effectiveStatus($query->today) : (self::SORT_COLUMNS[$query->sortBy] ?? '[email_date]');
        $direction = $query->descending ? 'DESC' : 'ASC';
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ', CAST(NULL AS NVARCHAR(MAX)) AS [body] FROM ' . self::TABLE . ' WHERE ' . $where
            . ' ORDER BY ' . $key . ' ' . $direction . ', [id] ' . $direction
            . ' OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY',
            $params + ['offset' => $query->offset, 'limit' => $query->limit],
        );

        return [array_map(self::record(...), $rows), $total];
    }

    public function find(string $id): ?ArRecord
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ', [body] FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::record($rows[0]);
    }

    public function attachmentsOf(string $recordId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT a.[id], a.[email_record_id], f.[original_name], f.[content_type], f.[byte_size], a.[is_remittance], a.[payer_name],
                    a.[payment_date], a.[payment_reference], a.[currency], a.[total_amount], a.[extraction_confidence], a.[reconciles],
                    a.[extraction_status], a.[extraction_error], a.[notes], a.[extracted_at], a.[created_date], a.[updated_date]
               FROM [finance].[ar_email_attachments] a
               JOIN [finance].[attachments] f ON f.[id] = a.[attachment_id]
              WHERE a.[email_record_id] = :id
              ORDER BY a.[created_date], a.[id]',
            ['id' => $recordId],
        );
        $lines = [];
        foreach ($this->connection->fetchAll(
            'SELECT [id], [email_attachment_id], [email_record_id], [invoice_number], [invoice_date], [gross_amount], [discount_amount], [paid_amount], [created_date]
               FROM [finance].[ar_remittance_lines] WHERE [email_record_id] = :id ORDER BY [line_no], [id]',
            ['id' => $recordId],
        ) as $line) {
            $lines[self::id($line['email_attachment_id'])][] = [
                'id' => self::id($line['id']),
                'attachment_id' => self::id($line['email_attachment_id']),
                'email_record_id' => self::id($line['email_record_id']),
                'invoice_number' => self::text($line['invoice_number']),
                'invoice_date' => self::textOrNull($line['invoice_date']),
                'gross_amount' => self::amount($line['gross_amount']),
                'discount_amount' => self::amount($line['discount_amount']),
                'paid_amount' => self::amount($line['paid_amount']),
                'created_date' => ArPresenter::iso(self::when($line['created_date'], 'created_date')),
            ];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = self::id($row['id']);
            $out[] = [
                'id' => $id,
                'email_record_id' => self::id($row['email_record_id']),
                'file_name' => self::text($row['original_name']),
                'content_type' => self::text($row['content_type']),
                'size_bytes' => self::int($row['byte_size']),
                'is_remittance' => self::bool($row['is_remittance']),
                'payer_name' => self::text($row['payer_name']),
                'payment_date' => self::textOrNull($row['payment_date']),
                'payment_reference' => self::textOrNull($row['payment_reference']),
                'currency' => self::text($row['currency']),
                'total_amount' => self::amount($row['total_amount']),
                'extraction_confidence' => self::amount($row['extraction_confidence']),
                'reconciles' => $row['reconciles'] === null ? null : self::bool($row['reconciles']),
                'extraction_status' => self::text($row['extraction_status']),
                'extraction_error' => self::textOrNull($row['extraction_error']),
                'notes' => self::text($row['notes']),
                'extracted_at' => ArPresenter::iso(self::whenOrNull($row['extracted_at'])),
                'created_date' => ArPresenter::iso(self::when($row['created_date'], 'created_date')),
                'updated_date' => ArPresenter::iso(self::when($row['updated_date'], 'updated_date')),
                'line_items' => $lines[$id] ?? [],
            ];
        }

        return $out;
    }

    public function exists(string $id): bool
    {
        return $this->connection->fetchAll('SELECT 1 AS [x] FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]) !== [];
    }

    public function attachmentFileId(string $recordId, string $attachmentId): ?string
    {
        $rows = $this->connection->fetchAll(
            'SELECT [attachment_id] FROM [finance].[ar_email_attachments] WHERE [id] = :id AND [email_record_id] = :record',
            ['id' => $attachmentId, 'record' => $recordId],
        );

        return $rows === [] ? null : self::id($rows[0]['attachment_id']);
    }

    public function addAttachment(ArKeptAttachment $attachment): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ar_email_attachments] ([id], [email_record_id], [attachment_id], [extraction_status], [created_date], [updated_date])
             VALUES (:id, :record, :file, :status, :at, :at2)',
            [
                'id' => $attachment->id,
                'record' => $attachment->recordId,
                'file' => $attachment->fileId,
                'status' => $attachment->extractionStatus,
                'at' => self::at($attachment->at),
                'at2' => self::at($attachment->at),
            ],
        );
    }

    public function overdueAwaitingReminder(string $today, int $limit): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new LogicException('The day must be yyyy-MM-dd.');
        }
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, min(1000, $limit)) . ') ' . self::COLUMNS . ', CAST(NULL AS NVARCHAR(MAX)) AS [body] FROM ' . self::TABLE . '
              WHERE [deleted_at] IS NULL AND [reminder_sent_at] IS NULL AND [follow_up_status] IN (N\'needs_review\', N\'in_progress\')
                AND [due_date] IS NOT NULL AND [due_date] < :today
              ORDER BY [due_date], [id]',
            ['today' => $today],
        );

        return array_map(self::record(...), $rows);
    }

    public function markReminderSent(string $id, DateTimeImmutable $at): bool
    {
        $rows = $this->connection->fetchAll(
            'UPDATE ' . self::TABLE . ' SET [reminder_sent_at] = :at OUTPUT INSERTED.[id] AS [id] WHERE [id] = :id AND [reminder_sent_at] IS NULL',
            ['at' => self::at($at), 'id' => $id],
        );

        return $rows !== [];
    }

    public function messageIdKnown(string $messageId): bool
    {
        return $this->connection->fetchAll('SELECT 1 AS [x] FROM ' . self::TABLE . ' WHERE [source_message_id] = :id', ['id' => $messageId]) !== [];
    }

    public function isTombstoned(string $messageId): bool
    {
        return $this->connection->fetchAll('SELECT 1 AS [x] FROM [finance].[ar_deleted_messages] WHERE [source_message_id] = :id', ['id' => $messageId]) !== [];
    }

    public function saveExtraction(string $attachmentId, string $recordId, ArExtraction $extraction, DateTimeImmutable $at, array $lineIds): void
    {
        $this->transactional(function () use ($attachmentId, $recordId, $extraction, $at, $lineIds): void {
            $this->connection->execute(
                'UPDATE [finance].[ar_email_attachments]
                    SET [is_remittance] = :remit, [payer_name] = :payer, [payment_date] = :pdate, [payment_reference] = :pref, [currency] = :currency,
                        [total_amount] = :total, [extraction_confidence] = :confidence, [reconciles] = :reconciles, [extraction_status] = N\'extracted\',
                        [extraction_error] = NULL, [notes] = :notes, [extracted_at] = :at, [updated_date] = :at2
                  WHERE [id] = :id',
                [
                    'remit' => $extraction->isRemittance ? 1 : 0,
                    'payer' => mb_substr($extraction->payerName, 0, 400),
                    'pdate' => $extraction->paymentDate === null ? null : mb_substr($extraction->paymentDate, 0, 40),
                    'pref' => $extraction->paymentReference === null ? null : mb_substr($extraction->paymentReference, 0, 200),
                    'currency' => mb_substr($extraction->currency, 0, 10),
                    'total' => $extraction->totalAmount,
                    'confidence' => max(0.0, min(1.0, $extraction->confidence)),
                    'reconciles' => $extraction->reconciles() === null ? null : ($extraction->reconciles() ? 1 : 0),
                    'notes' => $extraction->notes,
                    'at' => self::at($at),
                    'at2' => self::at($at),
                    'id' => $attachmentId,
                ],
            );
            foreach ($extraction->lines as $n => $line) {
                $this->connection->execute(
                    'INSERT INTO [finance].[ar_remittance_lines] ([id], [email_attachment_id], [email_record_id], [line_no], [invoice_number], [invoice_date], [gross_amount], [discount_amount], [paid_amount], [created_date])
                     VALUES (:id, :att, :record, :no, :invoice, :idate, :gross, :discount, :paid, :at)',
                    [
                        'id' => $lineIds[$n],
                        'att' => $attachmentId,
                        'record' => $recordId,
                        'no' => $n + 1,
                        'invoice' => mb_substr($line['invoice_number'], 0, 100),
                        'idate' => $line['invoice_date'] === null ? null : mb_substr($line['invoice_date'], 0, 40),
                        'gross' => $line['gross_amount'],
                        'discount' => $line['discount_amount'],
                        'paid' => $line['paid_amount'],
                        'at' => self::at($at),
                    ],
                );
            }
        });
    }

    public function markExtractionFailed(string $attachmentId, string $error, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ar_email_attachments] SET [extraction_status] = N\'failed\', [extraction_error] = :error, [updated_date] = :at WHERE [id] = :id',
            ['error' => mb_substr($error, 0, 1000), 'at' => self::at($at), 'id' => $attachmentId],
        );
    }

    public function insert(ArRecord $record): void
    {
        $this->write(
            'INSERT INTO ' . self::TABLE . ' (' . self::COLUMNS . ', [body])
             VALUES (:id, :emailDate, :customer, :senderEmail, :senderName, :subject, :summary, :category,
                     :assignedUser, :assignedName, :method, :response, :followUp, :due, :outcome,
                     :messageId, :unreachable, :mailbox, :confidence, :status, :salesRep,
                     :creditNote, :reminder, :deletedAt, :deletedByUser, :deletedBy, :created, :updated, :body)',
            [
                'id' => $record->id,
                'emailDate' => self::at($record->emailDate),
                'customer' => $record->customerName,
                'senderEmail' => $record->senderEmail,
                'senderName' => $record->senderName,
                'subject' => $record->subject,
                'summary' => $record->aiSummary,
                'category' => $record->followUpCategory,
                'assignedUser' => $record->assignedUserId,
                'assignedName' => $record->assignedName,
                'method' => $record->proposedResponseType,
                'response' => $record->proposedResponse,
                'followUp' => $record->followUpStatus,
                'due' => $record->dueDate,
                'outcome' => $record->finalOutcome,
                'messageId' => $record->sourceMessageId,
                'unreachable' => $record->messageUnreachable ? 1 : 0,
                'mailbox' => $record->mailbox,
                'confidence' => $record->classificationConfidence,
                'status' => $record->status,
                'salesRep' => $record->assignedSalesRepEmail,
                'creditNote' => $record->creditNoteRequestId,
                'reminder' => self::atOrNull($record->reminderSentAt),
                'deletedAt' => self::atOrNull($record->deletedAt),
                'deletedByUser' => $record->deletedByUserId,
                'deletedBy' => $record->deletedBy,
                'created' => self::at($record->createdAt),
                'updated' => self::at($record->updatedAt),
                'body' => $record->body,
            ],
            'That message has already been recorded',
        );
    }

    public function update(ArRecord $record): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . '
                SET [follow_up_category] = :category, [proposed_response_type] = :method, [follow_up_status] = :followUp, [status] = :status,
                    [assigned_user_id] = :assignedUser, [assigned_name] = :assignedName, [due_date] = :due, [final_outcome] = :outcome,
                    [customer_name] = :customer, [ai_summary] = :summary, [proposed_response] = :response,
                    [assigned_sales_rep_email] = :salesRep, [credit_note_request_id] = :creditNote, [reminder_sent_at] = :reminder, [updated_date] = :updated
              WHERE [id] = :id',
            [
                'category' => $record->followUpCategory,
                'method' => $record->proposedResponseType,
                'followUp' => $record->followUpStatus,
                'status' => $record->status,
                'assignedUser' => $record->assignedUserId,
                'assignedName' => $record->assignedName,
                'due' => $record->dueDate,
                'outcome' => $record->finalOutcome,
                'customer' => $record->customerName,
                'summary' => $record->aiSummary,
                'response' => $record->proposedResponse,
                'salesRep' => $record->assignedSalesRepEmail,
                'creditNote' => $record->creditNoteRequestId,
                'reminder' => self::atOrNull($record->reminderSentAt),
                'updated' => self::at($record->updatedAt),
                'id' => $record->id,
            ],
        );
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

    public function purge(string $id, DateTimeImmutable $at): void
    {
        $rows = $this->connection->fetchAll('SELECT [source_message_id] FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);
        if ($rows === []) {
            return;
        }
        $messageId = self::textOrNull($rows[0]['source_message_id']);
        if ($messageId !== null && $messageId !== '') {
            // A tombstone, so a later scan does not bring the message back; an older one is replaced.
            $this->connection->execute('DELETE FROM [finance].[ar_deleted_messages] WHERE [source_message_id] = :messageId', ['messageId' => $messageId]);
            $this->connection->execute(
                'INSERT INTO [finance].[ar_deleted_messages] ([source_message_id], [deleted_date]) VALUES (:messageId, :at)',
                ['messageId' => $messageId, 'at' => self::at($at)],
            );
        }
        // Children first: the keys have no cascade.
        $this->connection->execute('DELETE FROM [finance].[ar_remittance_lines] WHERE [email_record_id] = :id', ['id' => $id]);
        $this->connection->execute('DELETE FROM [finance].[ar_email_attachments] WHERE [email_record_id] = :id', ['id' => $id]);
        $this->connection->execute('DELETE FROM [finance].[ar_outbound_messages] WHERE [email_record_id] = :id', ['id' => $id]);
        $this->connection->execute('DELETE FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);
    }

    public function metrics(?DateTimeImmutable $start, ?DateTimeImmutable $end, string $today): array
    {
        $bounds = '';
        $params = [];
        if ($start !== null) {
            $bounds .= ' AND [email_date] >= :start';
            $params['start'] = self::at($start);
        }
        if ($end !== null) {
            $bounds .= ' AND [email_date] < :end';
            $params['end'] = self::at($end);
        }
        $live = ' FROM ' . self::TABLE . ' WHERE [deleted_at] IS NULL' . $bounds;
        $derived = self::effectiveStatus($today);

        $total = self::int($this->connection->fetchAll('SELECT COUNT(*) AS [n]' . $live, $params)[0]['n'] ?? 0);
        $statuses = [];
        foreach ($this->connection->fetchAll('SELECT ' . $derived . ' AS [k], COUNT(*) AS [n]' . $live . ' GROUP BY ' . $derived, $params) as $row) {
            $statuses[self::text($row['k'])] = self::int($row['n']);
        }
        $categories = [];
        foreach ($this->connection->fetchAll('SELECT [follow_up_category] AS [k], COUNT(*) AS [n]' . $live . ' GROUP BY [follow_up_category]', $params) as $row) {
            $categories[self::text($row['k'])] = self::int($row['n']);
        }
        $bin = self::int($this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM ' . self::TABLE . ' WHERE [deleted_at] IS NOT NULL')[0]['n'] ?? 0);
        $credit = [];
        foreach ($this->connection->fetchAll('SELECT [status] AS [k], COUNT(*) AS [n] FROM [finance].[ar_credit_note_requests] GROUP BY [status]') as $row) {
            $credit[self::text($row['k'])] = self::int($row['n']);
        }

        return ['total' => $total, 'statuses' => $statuses, 'categories' => $categories, 'in_bin' => $bin, 'credit_notes' => $credit];
    }

    // ---- the query ----------------------------------------------------------------------

    /**
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function where(ArRecordQuery $q): array
    {
        $where = [$q->deleted ? '[deleted_at] IS NOT NULL' : '[deleted_at] IS NULL'];
        $params = [];
        if ($q->category !== null) {
            $where[] = '[follow_up_category] = :category';
            $params['category'] = $q->category;
        }
        if ($q->followUpStatus !== null) {
            $where[] = self::effectiveStatus($q->today) . ' = :followUp';
            $params['followUp'] = $q->followUpStatus;
        }
        if ($q->status !== null) {
            $where[] = '[status] = :status';
            $params['status'] = $q->status;
        }
        if ($q->customerName !== null) {
            $where[] = "[customer_name] LIKE :customerName ESCAPE '!'";
            $params['customerName'] = self::contains($q->customerName);
        }
        if ($q->assignedPerson !== null) {
            $where[] = "[assigned_name] LIKE :assignedName ESCAPE '!'";
            $params['assignedName'] = self::contains($q->assignedPerson);
        }
        if ($q->start !== null) {
            $where[] = '[email_date] >= :start';
            $params['start'] = self::at($q->start);
        }
        if ($q->end !== null) {
            $where[] = '[email_date] < :end';
            $params['end'] = self::at($q->end);
        }
        if ($q->search !== null) {
            $columns = ['subject', 'customer_name', 'sender_email', 'sender_name', 'ai_summary'];
            if ($q->searchBody) {
                $columns[] = 'body';
            }
            $any = [];
            foreach ($columns as $i => $column) {
                $any[] = '[' . $column . "] LIKE :search{$i} ESCAPE '!'";
                $params['search' . $i] = self::contains($q->search);
            }
            $where[] = '(' . implode(' OR ', $any) . ')';
        }

        return [implode(' AND ', $where), $params];
    }

    /** `%text%` with the LIKE wildcards in the text escaped by `!`, so they match themselves. */
    private static function contains(string $text): string
    {
        return '%' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $text) . '%';
    }

    /**
     * The derived status as SQL (question Q8): an open record whose due date is before `$today` is overdue.
     * `$today` is the clock port's date, checked here to be a date, never a request's text.
     */
    private static function effectiveStatus(string $today): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new LogicException('The date overdue is judged against must be yyyy-MM-dd.');
        }

        return "(CASE WHEN [follow_up_status] IN (N'needs_review', N'in_progress') AND [due_date] IS NOT NULL AND [due_date] < CAST('" . $today . "' AS DATE) THEN N'overdue' ELSE [follow_up_status] END)";
    }

    private static function amount(mixed $value): ?float
    {
        return $value === null ? null : (float) (is_scalar($value) ? $value : 0);
    }

    /** @param array<string, mixed> $row */
    private static function record(array $row): ArRecord
    {
        $due = self::textOrNull($row['due_date']);

        return new ArRecord(
            self::id($row['id']),
            self::when($row['email_date'], 'email_date'),
            self::text($row['customer_name']),
            self::text($row['sender_email']),
            self::text($row['sender_name']),
            self::text($row['subject']),
            self::textOrNull($row['body']),
            self::text($row['ai_summary']),
            self::text($row['follow_up_category']),
            AzureSqlValueMapper::nullableInt($row['assigned_user_id']),
            self::text($row['assigned_name']),
            self::text($row['proposed_response_type']),
            self::text($row['proposed_response']),
            self::text($row['follow_up_status']),
            $due === null || $due === '' ? null : substr($due, 0, 10),
            self::text($row['final_outcome']),
            self::textOrNull($row['source_message_id']),
            self::bool($row['message_unreachable']),
            self::text($row['mailbox']),
            self::amount($row['classification_confidence']),
            self::text($row['status']),
            self::text($row['assigned_sales_rep_email']),
            self::textOrNull($row['credit_note_request_id']),
            self::whenOrNull($row['reminder_sent_at']),
            self::whenOrNull($row['deleted_at']),
            AzureSqlValueMapper::nullableInt($row['deleted_by_user_id']),
            self::text($row['deleted_by']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }
}
