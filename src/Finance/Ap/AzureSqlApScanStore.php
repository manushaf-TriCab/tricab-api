<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Contract\ApScanStore;
use TriCab\Core\Finance\Ap\Domain\ApScanRun;

/**
 * Scan runs in `finance.invoice_scan_runs`. The filtered unique index on `(claim_scope)` where `status` is `Listing` or
 * `Running` is the claim: a second `begin` while a run is open is refused by the database (one open scan for the whole app,
 * decision D76), and a run whose claim has expired is failed first, in the same transaction, so a scan whose page was
 * closed cannot hold the claim for ever. Counters change in one statement (`x = x + n`), never read-modify-write (R14).
 */
final class AzureSqlApScanStore extends AzureSqlApSupport implements ApScanStore
{
    private const TABLE = '[finance].[invoice_scan_runs]';

    private const COLUMNS = '[id], [requested_by_user_id], [requested_by], [from_date], [to_date], [mailboxes_json], [status],
        [claim_expires_at], [total_messages], [processed_messages], [invoice_emails], [other_emails], [duplicate_invoices], [errors],
        [created_date], [updated_date]';

    public function begin(ApScanRun $run, DateTimeImmutable $now): void
    {
        $this->transactional(function () use ($run, $now): void {
            $this->connection->execute(
                'UPDATE ' . self::TABLE . '
                    SET [status] = N\'Failed\', [claim_expires_at] = NULL, [updated_date] = :now
                  WHERE [status] IN (N\'Listing\', N\'Running\') AND ([claim_expires_at] IS NULL OR [claim_expires_at] <= :now2)',
                ['now' => self::at($now), 'now2' => self::at($now)],
            );
            $this->write(
                'INSERT INTO ' . self::TABLE . ' ([id], [requested_by_user_id], [requested_by], [from_date], [to_date], [mailboxes_json], [status],
                        [claim_expires_at], [created_date], [updated_date])
                 VALUES (:id, :userId, :by, :from, :to, :mailboxes, :status, :claim, :created, :updated)',
                [
                    'id' => $run->id,
                    'userId' => $run->requestedByUserId,
                    'by' => $run->requestedBy,
                    'from' => $run->fromDate,
                    'to' => $run->toDate,
                    'mailboxes' => json_encode($run->mailboxes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'status' => $run->status,
                    'claim' => self::atOrNull($run->claimExpiresAt),
                    'created' => self::at($run->createdAt),
                    'updated' => self::at($run->updatedAt),
                ],
                InMemoryApScanStore::CLAIM_CONFLICT,
            );
        });
    }

    public function find(string $id): ?ApScanRun
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::run($rows[0]);
    }

    public function markRunning(string $id, int $totalMessages, int $errors, DateTimeImmutable $claimUntil, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . ' SET [status] = N\'Running\', [total_messages] = :total, [errors] = :errors, [claim_expires_at] = :claim, [updated_date] = :at
              WHERE [id] = :id',
            ['total' => $totalMessages, 'errors' => $errors, 'claim' => self::at($claimUntil), 'at' => self::at($at), 'id' => $id],
        );
    }

    public function bump(string $id, array $increments, DateTimeImmutable $claimUntil, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . '
                SET [processed_messages] = [processed_messages] + :pm, [invoice_emails] = [invoice_emails] + :ie,
                    [other_emails] = [other_emails] + :oe, [duplicate_invoices] = [duplicate_invoices] + :di, [errors] = [errors] + :er,
                    [claim_expires_at] = CASE WHEN [status] IN (N\'Listing\', N\'Running\') THEN :claim ELSE [claim_expires_at] END,
                    [updated_date] = :at
              WHERE [id] = :id',
            [
                'pm' => $increments['processed_messages'] ?? 0,
                'ie' => $increments['invoice_emails'] ?? 0,
                'oe' => $increments['other_emails'] ?? 0,
                'di' => $increments['duplicate_invoices'] ?? 0,
                'er' => $increments['errors'] ?? 0,
                'claim' => self::at($claimUntil),
                'at' => self::at($at),
                'id' => $id,
            ],
        );
    }

    public function finish(string $id, string $status, DateTimeImmutable $at, ?int $errors = null): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . ' SET [status] = :status, [claim_expires_at] = NULL, [updated_date] = :at, [errors] = COALESCE(CAST(:errors AS INT), [errors])
              WHERE [id] = :id',
            ['status' => $status, 'at' => self::at($at), 'errors' => $errors, 'id' => $id],
        );
    }

    public function overlapping(string $from, string $to, int $limit = 20): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, $limit) . ') ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE [from_date] IS NOT NULL AND [to_date] IS NOT NULL AND [status] <> N\'Failed\' AND [total_messages] > 0
                AND [from_date] <= :to AND [to_date] >= :from
              ORDER BY [created_date] DESC, [id] DESC',
            ['to' => $to, 'from' => $from],
        );

        return array_map(self::run(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function run(array $row): ApScanRun
    {
        $decoded = json_decode(self::text($row['mailboxes_json']), true);
        $mailboxes = [];
        foreach (is_array($decoded) ? $decoded : [] as $mailbox) {
            if (is_string($mailbox)) {
                $mailboxes[] = $mailbox;
            }
        }

        return new ApScanRun(
            self::id($row['id']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
            $row['requested_by_user_id'] === null ? null : self::int($row['requested_by_user_id']),
            self::text($row['requested_by']),
            $row['from_date'] === null ? null : substr(self::text($row['from_date']), 0, 10),
            $row['to_date'] === null ? null : substr(self::text($row['to_date']), 0, 10),
            $mailboxes,
            self::text($row['status']),
            self::whenOrNull($row['claim_expires_at']),
            self::int($row['total_messages']),
            self::int($row['processed_messages']),
            self::int($row['invoice_emails']),
            self::int($row['other_emails']),
            self::int($row['duplicate_invoices']),
            self::int($row['errors']),
        );
    }
}
