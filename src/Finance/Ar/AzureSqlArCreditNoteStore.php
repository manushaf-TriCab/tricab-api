<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore;
use TriCab\Core\Finance\Ar\Domain\ArCreditNote;

/**
 * `finance.ar_credit_note_requests`. The token's SHA-256 is the only trace of a token (a unique index on it), and the two
 * conditional updates (a rep's submit, a new token) say in one statement whether they won.
 */
final class AzureSqlArCreditNoteStore extends AzureSqlArSupport implements ArCreditNoteStore
{
    private const TABLE = '[finance].[ar_credit_note_requests]';

    private const COLUMNS = '[id], [email_record_id], [customer_name], [customer_email], [sales_rep_name], [sales_rep_email], [invoice_number], [credit_amount],
        [reason], [original_request], [token_hash], [token_expires_at], [status], [form_sent_date], [submitted_date], [reviewed_at], [created_date], [updated_date]';

    private const OPEN = "(N'form_sent', N'awaiting_sales_response')";

    public function insertCreditNote(ArCreditNote $note): void
    {
        $this->write(
            'INSERT INTO ' . self::TABLE . ' (' . self::COLUMNS . ')
             VALUES (:id, :record, :customer, :customerEmail, :repName, :repEmail, :invoice, :amount, :reason, :original, :hash, :expires, :status, :sent, :submitted, :reviewed, :created, :updated)',
            [
                'id' => $note->id,
                'record' => $note->emailRecordId,
                'customer' => $note->customerName,
                'customerEmail' => $note->customerEmail,
                'repName' => $note->salesRepName,
                'repEmail' => $note->salesRepEmail,
                'invoice' => $note->invoiceNumber,
                'amount' => number_format($note->creditAmount, 2, '.', ''),
                'reason' => $note->reason,
                'original' => $note->originalRequest,
                'hash' => $note->tokenHash,
                'expires' => self::at($note->tokenExpiresAt),
                'status' => $note->status,
                'sent' => self::atOrNull($note->formSentDate),
                'submitted' => self::atOrNull($note->submittedDate),
                'reviewed' => self::atOrNull($note->reviewedAt),
                'created' => self::at($note->createdAt),
                'updated' => self::at($note->updatedAt),
            ],
            'That token is already in use',
        );
    }

    public function findCreditNote(string $id): ?ArCreditNote
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::note($rows[0]);
    }

    public function pageCreditNotes(?string $status, ?string $customerName, ?string $salesRepEmail, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = '[status] = :status';
            $params['status'] = $status;
        }
        if ($customerName !== null) {
            $where[] = "[customer_name] LIKE :customer ESCAPE '!'";
            $params['customer'] = '%' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $customerName) . '%';
        }
        if ($salesRepEmail !== null) {
            $where[] = '[sales_rep_email] = :repEmail';
            $params['repEmail'] = $salesRepEmail;
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = self::int($this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM ' . self::TABLE . $clause, $params)[0]['n'] ?? 0);
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . $clause . ' ORDER BY [created_date] DESC, [id] OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY',
            $params + ['offset' => $offset, 'limit' => $limit],
        );

        return [array_map(self::note(...), $rows), $total];
    }

    public function updateCreditNote(ArCreditNote $note): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . '
                SET [customer_name] = :customer, [customer_email] = :customerEmail, [sales_rep_name] = :repName, [sales_rep_email] = :repEmail,
                    [invoice_number] = :invoice, [credit_amount] = :amount, [reason] = :reason, [original_request] = :original,
                    [status] = :status, [reviewed_at] = :reviewed, [updated_date] = :updated
              WHERE [id] = :id',
            [
                'customer' => $note->customerName,
                'customerEmail' => $note->customerEmail,
                'repName' => $note->salesRepName,
                'repEmail' => $note->salesRepEmail,
                'invoice' => $note->invoiceNumber,
                'amount' => number_format($note->creditAmount, 2, '.', ''),
                'reason' => $note->reason,
                'original' => $note->originalRequest,
                'status' => $note->status,
                'reviewed' => self::atOrNull($note->reviewedAt),
                'updated' => self::at($note->updatedAt),
                'id' => $note->id,
            ],
        );
    }

    public function replaceCreditNoteToken(string $id, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $at): bool
    {
        $rows = $this->connection->fetchAll(
            'UPDATE ' . self::TABLE . '
                SET [token_hash] = :hash, [token_expires_at] = :expires, [form_sent_date] = :sent, [updated_date] = :updated
             OUTPUT INSERTED.[id] AS [id]
              WHERE [id] = :id AND [status] IN ' . self::OPEN,
            ['hash' => $tokenHash, 'expires' => self::at($expiresAt), 'sent' => self::at($at), 'updated' => self::at($at), 'id' => $id],
        );

        return $rows !== [];
    }

    public function submitCreditNote(string $id, string $invoiceNumber, float $creditAmount, string $reason, string $salesRepName, DateTimeImmutable $at): bool
    {
        $rows = $this->connection->fetchAll(
            'UPDATE ' . self::TABLE . '
                SET [invoice_number] = :invoice, [credit_amount] = :amount, [reason] = :reason, [sales_rep_name] = :repName,
                    [status] = N\'submitted\', [submitted_date] = :submitted, [updated_date] = :updated
             OUTPUT INSERTED.[id] AS [id]
              WHERE [id] = :id AND [status] IN ' . self::OPEN,
            [
                'invoice' => $invoiceNumber,
                'amount' => number_format($creditAmount, 2, '.', ''),
                'reason' => $reason,
                'repName' => $salesRepName,
                'submitted' => self::at($at),
                'updated' => self::at($at),
                'id' => $id,
            ],
        );

        return $rows !== [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function note(array $row): ArCreditNote
    {
        return new ArCreditNote(
            self::id($row['id']),
            $row['email_record_id'] === null ? null : self::id($row['email_record_id']),
            self::text($row['customer_name']),
            self::text($row['customer_email']),
            self::text($row['sales_rep_name']),
            self::text($row['sales_rep_email']),
            self::text($row['invoice_number']),
            round((float) self::text($row['credit_amount']), 2),
            self::text($row['reason']),
            self::text($row['original_request']),
            self::text($row['token_hash']),
            self::when($row['token_expires_at'], 'token_expires_at'),
            self::text($row['status']),
            self::whenOrNull($row['form_sent_date']),
            self::whenOrNull($row['submitted_date']),
            self::whenOrNull($row['reviewed_at']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }
}
