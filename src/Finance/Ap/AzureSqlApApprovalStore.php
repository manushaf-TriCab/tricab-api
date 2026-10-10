<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use JsonException;
use TriCab\Core\Finance\Ap\Contract\ApApprovalStore;
use TriCab\Core\Finance\Ap\Domain\ApApprovalBatch;
use TriCab\Core\Finance\Ap\Domain\ApApprovalDecision;
use TriCab\Core\Finance\Module;

/**
 * The approvals over `create-finance-invoice-schema.sql`: `finance.invoice_approval_batches`, `_items` (unique per batch and
 * invoice) and `_decisions`. A list of batches is read in a fixed number of statements: the batches, their items, their
 * decisions and the request mail's recipients, each for all of them at once.
 *
 * The first decision wins in one conditional UPDATE (`WHERE status = 'Pending'`) whose OUTPUT says whether this request
 * changed the row. The recipients of the request mail come from the mail ledger through `invoice_outbound_messages`.
 */
final class AzureSqlApApprovalStore extends AzureSqlApSupport implements ApApprovalStore
{
    private const BATCH_COLUMNS = '[id], [prepared_by_user_id], [prepared_by_name], [prepared_by_email], [status], [note], [decided_by_user_id],
        [decided_by_name], [decided_at], [decision_comment], [created_date], [updated_date]';

    public function add(ApApprovalBatch $batch, array $items, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[invoice_approval_batches]
                ([id], [prepared_by_user_id], [prepared_by_name], [prepared_by_email], [status], [note], [created_date], [updated_date])
             VALUES (:id, :userId, :name, :email, :status, :note, :created, :updated)',
            [
                'id' => $batch->id,
                'userId' => $batch->preparedByUserId,
                'name' => $batch->preparedByName,
                'email' => $batch->preparedByEmail,
                'status' => $batch->status,
                'note' => $batch->note,
                'created' => self::at($batch->createdAt),
                'updated' => self::at($batch->updatedAt),
            ],
        );
        foreach ($items as $itemId => $invoiceId) {
            $this->write(
                'INSERT INTO [finance].[invoice_approval_items] ([id], [batch_id], [invoice_id], [created_date]) VALUES (:id, :batch, :invoice, :created)',
                ['id' => (string) $itemId, 'batch' => $batch->id, 'invoice' => $invoiceId, 'created' => self::at($at)],
                'The invoice is already in this approval request',
            );
        }
    }

    public function find(string $id): ?ApApprovalBatch
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::BATCH_COLUMNS . ' FROM [finance].[invoice_approval_batches] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::batch($rows[0]);
    }

    public function recent(int $limit, ?array $mailboxes): array
    {
        $params = [];
        $where = '';
        if ($mailboxes !== null) {
            $marks = [];
            foreach ($mailboxes as $n => $mailbox) {
                $marks[] = ':mailbox' . $n;
                $params['mailbox' . $n] = $mailbox;
            }
            // A batch is visible only when every one of its invoices is: a hand capture (no mailbox) is visible to all.
            $where = ' WHERE NOT EXISTS (
                SELECT 1 FROM [finance].[invoice_approval_items] ai
                  JOIN [finance].[invoice_invoices] i ON i.[id] = ai.[invoice_id]
                 WHERE ai.[batch_id] = b.[id]
                   AND LTRIM(RTRIM(COALESCE(i.[mailbox], N\'\'))) <> N\'\'
                   AND LOWER(LTRIM(RTRIM(i.[mailbox]))) NOT IN (' . ($marks === [] ? 'N\'\'' : implode(', ', $marks)) . '))';
        }
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(0, $limit) . ') ' . self::BATCH_COLUMNS . ' FROM [finance].[invoice_approval_batches] b' . $where
            . ' ORDER BY b.[created_date] DESC, b.[id] DESC',
            $params,
        );

        return array_map(self::batch(...), $rows);
    }

    public function invoiceIdsOf(array $batchIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($batchIds)), 500) as $chunk) {
            [$marks, $params] = self::in('batch', $chunk);
            $rows = $this->connection->fetchAll(
                'SELECT [batch_id], [invoice_id] FROM [finance].[invoice_approval_items] WHERE [batch_id] IN (' . $marks . ') ORDER BY [created_date], [id]',
                $params,
            );
            foreach ($rows as $row) {
                $out[self::id($row['batch_id'])][] = self::id($row['invoice_id']);
            }
        }

        return $out;
    }

    public function decisionsOf(array $batchIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($batchIds)), 500) as $chunk) {
            [$marks, $params] = self::in('batch', $chunk);
            $rows = $this->connection->fetchAll(
                'SELECT [id], [batch_id], [approver_user_id], [approver_name], [approver_email], [decision], [comment], [created_date]
                   FROM [finance].[invoice_approval_decisions] WHERE [batch_id] IN (' . $marks . ') ORDER BY [created_date], [id]',
                $params,
            );
            foreach ($rows as $row) {
                $out[self::id($row['batch_id'])][] = new ApApprovalDecision(
                    self::id($row['id']),
                    self::id($row['batch_id']),
                    $row['approver_user_id'] === null ? null : self::int($row['approver_user_id']),
                    self::text($row['approver_name']),
                    self::text($row['approver_email']),
                    self::text($row['decision']),
                    self::text($row['comment']),
                    self::when($row['created_date'], 'created_date'),
                );
            }
        }

        return $out;
    }

    public function notifiedTo(array $batchIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($batchIds)), 500) as $chunk) {
            [$marks, $params] = self::in('batch', $chunk);
            $rows = $this->connection->fetchAll(
                'SELECT om.[approval_batch_id] AS [batch_id], d.[to_addresses_json] AS [to_json]
                   FROM [finance].[invoice_outbound_messages] om
                   JOIN [finance].[mail_deliveries] d ON d.[id] = om.[delivery_id]
                  WHERE om.[approval_batch_id] IN (' . $marks . ') AND d.[application_key] = :app
                    AND d.[idempotency_key] = CONCAT(N\'ap-approval:\', RTRIM(om.[approval_batch_id]), N\':request\')
                  ORDER BY om.[created_date], om.[id]',
                $params + ['app' => Module::APP_AP],
            );
            foreach ($rows as $row) {
                $batchId = self::id($row['batch_id']);
                if (!isset($out[$batchId])) {
                    $out[$batchId] = self::addresses($row['to_json']);
                }
            }
        }

        return $out;
    }

    public function decide(string $id, string $status, ?int $userId, string $name, string $comment, DateTimeImmutable $at): bool
    {
        $rows = $this->connection->fetchAll(
            'UPDATE [finance].[invoice_approval_batches]
                SET [status] = :status, [decided_by_user_id] = :userId, [decided_by_name] = :name, [decided_at] = :at,
                    [decision_comment] = :comment, [updated_date] = :updated
             OUTPUT INSERTED.[id] AS [id]
              WHERE [id] = :id AND [status] = :pending',
            [
                'status' => $status,
                'userId' => $userId,
                'name' => $name,
                'at' => self::at($at),
                'comment' => $comment,
                'updated' => self::at($at),
                'id' => $id,
                'pending' => ApApprovalBatch::PENDING,
            ],
        );

        return $rows !== [];
    }

    public function addDecision(ApApprovalDecision $decision): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[invoice_approval_decisions]
                ([id], [batch_id], [approver_user_id], [approver_name], [approver_email], [decision], [comment], [created_date])
             VALUES (:id, :batch, :userId, :name, :email, :decision, :comment, :created)',
            [
                'id' => $decision->id,
                'batch' => $decision->batchId,
                'userId' => $decision->approverUserId,
                'name' => $decision->approverName,
                'email' => $decision->approverEmail,
                'decision' => $decision->decision,
                'comment' => $decision->comment,
                'created' => self::at($decision->createdAt),
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function batch(array $row): ApApprovalBatch
    {
        return new ApApprovalBatch(
            self::id($row['id']),
            $row['prepared_by_user_id'] === null ? null : self::int($row['prepared_by_user_id']),
            self::text($row['prepared_by_name']),
            self::text($row['prepared_by_email']),
            self::text($row['status']),
            self::text($row['note']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
            $row['decided_by_user_id'] === null ? null : self::int($row['decided_by_user_id']),
            self::text($row['decided_by_name']),
            self::whenOrNull($row['decided_at']),
            self::textOrNull($row['decision_comment']),
        );
    }

    /**
     * @return list<string>
     */
    private static function addresses(mixed $json): array
    {
        try {
            $decoded = json_decode(self::text($json), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }

    /**
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
}
