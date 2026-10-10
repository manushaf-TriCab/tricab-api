<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use LogicException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailAttachment;
use TriCab\Core\Finance\Ap\Domain\ApEmailQuery;

/**
 * The AP Mailbox in `finance.invoice_emails` and `finance.invoice_email_attachments` (`create-finance-invoice-schema.sql`).
 *
 * The mailbox scope, the period and the classification are applied in the one page query, before the page; the order is
 * `received_at DESC, id DESC` (an email with no time sorts last and matches no period). The links an email's page needs are
 * ONE query for all its ids (D75). The text compares are the database's: case-insensitive, so a Graph id is found in any
 * case (R29), the same as the unique key (mailbox, Graph message id).
 */
final class AzureSqlApEmailStore extends AzureSqlApSupport implements ApEmailStore
{
    private const TABLE = '[finance].[invoice_emails]';
    private const ATTACHMENTS = '[finance].[invoice_email_attachments]';

    private const COLUMNS = [
        'id', 'graph_message_id', 'mailbox', 'received_at', 'sender_name', 'sender_email', 'subject', 'body_preview', 'web_link',
        'has_attachments', 'classification', 'classification_reason', 'classification_confidence', 'review_status',
        'proposed_response', 'scan_run_id', 'deleted_at', 'deleted_by_user_id', 'deleted_by', 'created_date', 'updated_date',
    ];

    private const ATTACHMENT_COLUMNS = [
        'id', 'email_record_id', 'graph_attachment_id', 'name', 'content_type', 'size', 'content_hash', 'file_id', 'is_invoice',
        'invoice_id', 'duplicate_of_invoice_id', 'created_date', 'updated_date',
    ];

    /** The text columns that are `NOT NULL DEFAULT ''`: a null written to one is an empty text. */
    private const NOT_NULL_TEXT = ['sender_name', 'sender_email', 'subject', 'body_preview', 'web_link'];

    public function page(ApEmailQuery $query): array
    {
        [$where, $params] = $this->where($query->deleted, $query->classification, $query->start, $query->end, $query->mailboxes);
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::select() . ' FROM ' . self::TABLE . ' WHERE ' . $where
            . ' ORDER BY [received_at] DESC, [id] DESC OFFSET ' . max(0, $query->offset) . ' ROWS FETCH NEXT '
            . max(1, min(ApEmailQuery::MAX_LIMIT, $query->limit)) . ' ROWS ONLY',
            $params,
        );

        return array_map(self::email(...), $rows);
    }

    public function counts(?DateTimeImmutable $start, ?DateTimeImmutable $end, ?array $mailboxes): array
    {
        [$where, $params] = $this->where(false, null, $start, $end, $mailboxes);
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [total],
                    COALESCE(SUM(CASE WHEN [classification] = N\'Invoice\' THEN 1 ELSE 0 END), 0) AS [invoice],
                    COALESCE(SUM(CASE WHEN [classification] = N\'Other\' THEN 1 ELSE 0 END), 0) AS [other],
                    COALESCE(SUM(CASE WHEN [classification] = N\'Error\' THEN 1 ELSE 0 END), 0) AS [errors]
               FROM ' . self::TABLE . ' WHERE ' . $where,
            $params,
        );
        $row = $rows[0] ?? [];

        return [
            'total' => self::int($row['total'] ?? 0),
            'invoice' => self::int($row['invoice'] ?? 0),
            'other' => self::int($row['other'] ?? 0),
            'errors' => self::int($row['errors'] ?? 0),
        ];
    }

    public function find(string $id): ?ApEmail
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::select() . ' FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::email($rows[0]);
    }

    public function findMany(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            [$marks, $params] = self::in('id', $chunk);
            foreach ($this->connection->fetchAll('SELECT ' . self::select() . ' FROM ' . self::TABLE . ' WHERE [id] IN (' . $marks . ')', $params) as $row) {
                $email = self::email($row);
                $out[$email->id] = $email;
            }
        }

        return $out;
    }

    public function findByMessage(string $mailbox, string $graphMessageId): ?ApEmail
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) ' . self::select() . ' FROM ' . self::TABLE . '
              WHERE LOWER(LTRIM(RTRIM([mailbox]))) = :mailbox AND [graph_message_id] = :message ORDER BY [created_date], [id]',
            ['mailbox' => strtolower(trim($mailbox)), 'message' => $graphMessageId],
        );

        return $rows === [] ? null : self::email($rows[0]);
    }

    public function insert(ApEmail $email): void
    {
        $names = array_map(static fn (string $c): string => '[' . $c . ']', self::COLUMNS);
        $marks = array_map(static fn (string $c): string => ':' . $c, self::COLUMNS);
        $this->write(
            'INSERT INTO ' . self::TABLE . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $marks) . ')',
            [
                'id' => $email->id,
                'graph_message_id' => $email->graphMessageId,
                'mailbox' => $email->mailbox,
                'received_at' => self::atOrNull($email->receivedAt),
                'sender_name' => $email->senderName,
                'sender_email' => $email->senderEmail,
                'subject' => $email->subject,
                'body_preview' => $email->bodyPreview,
                'web_link' => $email->webLink,
                'has_attachments' => $email->hasAttachments ? 1 : 0,
                'classification' => $email->classification,
                'classification_reason' => $email->classificationReason,
                'classification_confidence' => self::confidence($email->classificationConfidence),
                'review_status' => $email->reviewStatus,
                'proposed_response' => $email->proposedResponse,
                'scan_run_id' => $email->scanRunId,
                'deleted_at' => self::atOrNull($email->deletedAt),
                'deleted_by_user_id' => $email->deletedByUserId,
                'deleted_by' => $email->deletedBy,
                'created_date' => self::at($email->createdAt),
                'updated_date' => self::at($email->updatedAt),
            ],
            'That message is already stored',
        );
    }

    public function update(string $id, array $columns, DateTimeImmutable $at): void
    {
        $unknown = array_diff(array_keys($columns), ApEmail::UPDATABLE);
        if ($unknown !== []) {
            throw new LogicException('Not an updatable email column: ' . implode(', ', $unknown));
        }
        $sets = ['[updated_date] = :updated'];
        $params = ['id' => $id, 'updated' => self::at($at)];
        foreach ($columns as $column => $value) {
            $sets[] = '[' . $column . '] = :c_' . $column;
            $params['c_' . $column] = match (true) {
                $column === 'received_at' => $value instanceof DateTimeImmutable ? self::at($value) : null,
                $column === 'has_attachments' => $value === true ? 1 : 0,
                $column === 'classification_confidence' => self::confidence(is_int($value) || is_float($value) ? (float) $value : null),
                in_array($column, self::NOT_NULL_TEXT, true) => is_string($value) ? $value : '',
                default => is_string($value) ? $value : null,
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

    public function duplicateInvoiceIdsByEmail(array $emailIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($emailIds)), 1000) as $chunk) {
            [$marks, $params] = self::in('email', $chunk);
            $rows = $this->connection->fetchAll(
                'SELECT a.[email_record_id] AS [email], a.[duplicate_of_invoice_id] AS [invoice]
                   FROM ' . self::ATTACHMENTS . ' a
                   JOIN [finance].[invoice_invoices] i ON i.[id] = a.[duplicate_of_invoice_id]
                  WHERE a.[email_record_id] IN (' . $marks . ') AND a.[is_invoice] = 1 AND a.[invoice_id] IS NULL
                  ORDER BY a.[created_date], a.[id]',
                $params,
            );
            foreach ($rows as $row) {
                $email = self::id($row['email']);
                $invoice = self::id($row['invoice']);
                if (!in_array($invoice, $out[$email] ?? [], true)) {
                    $out[$email][] = $invoice;
                }
            }
        }

        return $out;
    }

    public function attachmentsOf(string $emailId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::attachmentSelect() . ' FROM ' . self::ATTACHMENTS . ' WHERE [email_record_id] = :email ORDER BY [created_date], [id]',
            ['email' => $emailId],
        );

        return array_map(self::attachment(...), $rows);
    }

    public function findAttachment(string $emailId, ?string $graphAttachmentId): ?ApEmailAttachment
    {
        if ($graphAttachmentId === null || $graphAttachmentId === '') {
            return null;
        }
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) ' . self::attachmentSelect() . ' FROM ' . self::ATTACHMENTS . ' WHERE [email_record_id] = :email AND [graph_attachment_id] = :attachment',
            ['email' => $emailId, 'attachment' => $graphAttachmentId],
        );

        return $rows === [] ? null : self::attachment($rows[0]);
    }

    public function saveAttachment(ApEmailAttachment $attachment): void
    {
        $names = array_map(static fn (string $c): string => '[' . $c . ']', self::ATTACHMENT_COLUMNS);
        $marks = array_map(static fn (string $c): string => ':' . $c, self::ATTACHMENT_COLUMNS);
        $this->write(
            'INSERT INTO ' . self::ATTACHMENTS . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $marks) . ')',
            [
                'id' => $attachment->id,
                'email_record_id' => $attachment->emailRecordId,
                'graph_attachment_id' => $attachment->graphAttachmentId,
                'name' => $attachment->name,
                'content_type' => $attachment->contentType,
                'size' => $attachment->size,
                'content_hash' => $attachment->contentHash,
                'file_id' => $attachment->fileId,
                'is_invoice' => $attachment->isInvoice ? 1 : 0,
                'invoice_id' => $attachment->invoiceId,
                'duplicate_of_invoice_id' => $attachment->duplicateOfInvoiceId,
                'created_date' => self::at($attachment->createdAt),
                'updated_date' => self::at($attachment->updatedAt),
            ],
            'That attachment is already recorded',
        );
    }

    public function updateAttachment(string $id, array $columns, DateTimeImmutable $at): void
    {
        $unknown = array_diff(array_keys($columns), ApEmailAttachment::UPDATABLE);
        if ($unknown !== []) {
            throw new LogicException('Not an updatable attachment column: ' . implode(', ', $unknown));
        }
        $sets = ['[updated_date] = :updated'];
        $params = ['id' => $id, 'updated' => self::at($at)];
        foreach ($columns as $column => $value) {
            $sets[] = '[' . $column . '] = :c_' . $column;
            $params['c_' . $column] = match (true) {
                $column === 'is_invoice' => $value === true ? 1 : 0,
                $column === 'size' => is_int($value) ? $value : null,
                $column === 'name', $column === 'content_type' => is_string($value) ? $value : '',
                default => is_string($value) ? $value : null,
            };
        }
        $this->connection->execute('UPDATE ' . self::ATTACHMENTS . ' SET ' . implode(', ', $sets) . ' WHERE [id] = :id', $params);
    }

    // ---- the query ----------------------------------------------------------------------

    /**
     * @param list<string>|null $mailboxes
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function where(bool $deleted, ?string $classification, ?DateTimeImmutable $start, ?DateTimeImmutable $end, ?array $mailboxes): array
    {
        $where = [$deleted ? '[deleted_at] IS NOT NULL' : '[deleted_at] IS NULL'];
        $params = [];
        if ($start !== null) {
            $where[] = '[received_at] >= :start';
            $params['start'] = self::at($start);
        }
        if ($end !== null) {
            $where[] = '[received_at] < :end';
            $params['end'] = self::at($end);
        }
        if ($classification !== null) {
            $where[] = '[classification] = :classification';
            $params['classification'] = $classification;
        }
        if ($mailboxes !== null) {
            if ($mailboxes === []) {
                $where[] = '1 = 0';
            } else {
                [$marks, $in] = self::in('mailbox', $mailboxes);
                $where[] = 'LOWER(LTRIM(RTRIM([mailbox]))) IN (' . $marks . ')';
                $params += $in;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    private static function select(): string
    {
        return implode(', ', array_map(static fn (string $c): string => '[' . $c . ']', self::COLUMNS));
    }

    private static function attachmentSelect(): string
    {
        return implode(', ', array_map(static fn (string $c): string => '[' . $c . ']', self::ATTACHMENT_COLUMNS));
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

    /** A confidence as the `DECIMAL(9,8)` column takes it. */
    private static function confidence(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 8, '.', '');
    }

    /** @param array<string, mixed> $row */
    private static function email(array $row): ApEmail
    {
        return new ApEmail(
            self::id($row['id']),
            self::text($row['graph_message_id']),
            self::text($row['mailbox']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
            receivedAt: self::whenOrNull($row['received_at']),
            senderName: self::text($row['sender_name']),
            senderEmail: self::text($row['sender_email']),
            subject: self::text($row['subject']),
            bodyPreview: self::text($row['body_preview']),
            webLink: self::text($row['web_link']),
            hasAttachments: self::bool($row['has_attachments']),
            classification: self::text($row['classification']),
            classificationReason: self::textOrNull($row['classification_reason']),
            classificationConfidence: $row['classification_confidence'] === null ? null : (float) (is_scalar($row['classification_confidence']) ? $row['classification_confidence'] : 0),
            reviewStatus: self::text($row['review_status']),
            proposedResponse: self::textOrNull($row['proposed_response']),
            scanRunId: self::textOrNull($row['scan_run_id']),
            deletedAt: self::whenOrNull($row['deleted_at']),
            deletedByUserId: AzureSqlValueMapper::nullableInt($row['deleted_by_user_id']),
            deletedBy: self::text($row['deleted_by']),
        );
    }

    /** @param array<string, mixed> $row */
    private static function attachment(array $row): ApEmailAttachment
    {
        return new ApEmailAttachment(
            self::id($row['id']),
            self::id($row['email_record_id']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
            graphAttachmentId: self::textOrNull($row['graph_attachment_id']),
            name: self::text($row['name']),
            contentType: self::text($row['content_type']),
            size: AzureSqlValueMapper::nullableInt($row['size']),
            contentHash: self::textOrNull($row['content_hash']),
            fileId: self::textOrNull($row['file_id']),
            isInvoice: self::bool($row['is_invoice']),
            invoiceId: self::textOrNull($row['invoice_id']),
            duplicateOfInvoiceId: self::textOrNull($row['duplicate_of_invoice_id']),
        );
    }
}
