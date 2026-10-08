<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Contract\ArScanStore;
use TriCab\Core\Finance\Ar\Domain\ArScanRun;

/**
 * Scan runs in `finance.ar_scan_runs`. The filtered unique index on `(mailbox)` where `status = 'running'` is the claim:
 * a second `begin` for a mailbox with a live run is refused by the database, and a run whose claim has expired is
 * failed first so it cannot hold the mailbox forever.
 */
final class AzureSqlArScanStore extends AzureSqlArSupport implements ArScanStore
{
    private const COLUMNS = '[id], [started_at], [finished_at], [mailbox], [status], [watermark], [total_fetched], [new_records], [skipped_records],
        [failed_enrichments], [errors], [from_date], [to_date], [requested_by_user_id], [requested_by], [total_messages], [processed_messages], [claim_expires_at]';

    public function begin(ArScanRun $run, DateTimeImmutable $now): void
    {
        $this->transactional(function () use ($run, $now): void {
            $this->connection->execute(
                'UPDATE [finance].[ar_scan_runs]
                    SET [status] = N\'failed\', [finished_at] = :now, [errors] = N\'The scan stopped without finishing\', [claim_expires_at] = NULL
                  WHERE [mailbox] = :mailbox AND [status] = N\'running\' AND ([claim_expires_at] IS NULL OR [claim_expires_at] <= :now2)',
                ['now' => self::at($now), 'now2' => self::at($now), 'mailbox' => $run->mailbox],
            );
            $this->write(
                'INSERT INTO [finance].[ar_scan_runs] ([id], [started_at], [mailbox], [status], [from_date], [to_date], [requested_by_user_id], [requested_by], [claim_expires_at])
                 VALUES (:id, :started, :mailbox, N\'running\', :from, :to, :userId, :by, :claim)',
                [
                    'id' => $run->id,
                    'started' => self::at($run->startedAt),
                    'mailbox' => $run->mailbox,
                    'from' => $run->fromDate,
                    'to' => $run->toDate,
                    'userId' => $run->requestedByUserId,
                    'by' => $run->requestedBy,
                    'claim' => self::atOrNull($run->claimExpiresAt),
                ],
                'A scan of this mailbox is already running',
            );
        });
    }

    public function find(string $id): ?ArScanRun
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ar_scan_runs] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::run($rows[0]);
    }

    public function bump(string $id, array $increments, DateTimeImmutable $claimUntil): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ar_scan_runs]
                SET [total_fetched] = [total_fetched] + :tf, [new_records] = [new_records] + :nr, [skipped_records] = [skipped_records] + :sr,
                    [failed_enrichments] = [failed_enrichments] + :fe, [total_messages] = [total_messages] + :tm, [processed_messages] = [processed_messages] + :pm,
                    [claim_expires_at] = CASE WHEN [status] = N\'running\' THEN :claim ELSE [claim_expires_at] END
              WHERE [id] = :id',
            [
                'tf' => $increments['total_fetched'] ?? 0,
                'nr' => $increments['new_records'] ?? 0,
                'sr' => $increments['skipped_records'] ?? 0,
                'fe' => $increments['failed_enrichments'] ?? 0,
                'tm' => $increments['total_messages'] ?? 0,
                'pm' => $increments['processed_messages'] ?? 0,
                'claim' => self::at($claimUntil),
                'id' => $id,
            ],
        );
    }

    public function finish(string $id, string $status, DateTimeImmutable $at, ?DateTimeImmutable $watermark, ArScanRun $counters): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ar_scan_runs]
                SET [status] = :status, [finished_at] = :at, [watermark] = :watermark, [total_fetched] = :tf, [new_records] = :nr,
                    [skipped_records] = :sr, [failed_enrichments] = :fe, [errors] = :errors, [claim_expires_at] = NULL
              WHERE [id] = :id',
            [
                'status' => $status,
                'at' => self::at($at),
                'watermark' => self::atOrNull($watermark),
                'tf' => $counters->totalFetched,
                'nr' => $counters->newRecords,
                'sr' => $counters->skippedRecords,
                'fe' => $counters->failedEnrichments,
                'errors' => $counters->errors === '' ? null : $counters->errors,
                'id' => $id,
            ],
        );
    }

    public function latestWatermark(string $mailbox): ?DateTimeImmutable
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP 1 [watermark] FROM [finance].[ar_scan_runs] WHERE [mailbox] = :mailbox AND [status] = N\'completed\' AND [watermark] IS NOT NULL ORDER BY [watermark] DESC',
            ['mailbox' => $mailbox],
        );

        return $rows === [] ? null : self::whenOrNull($rows[0]['watermark']);
    }

    public function overlapping(string $from, string $to, int $limit = 10): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (:limit) ' . self::COLUMNS . ' FROM [finance].[ar_scan_runs]
              WHERE [from_date] IS NOT NULL AND [to_date] IS NOT NULL AND [status] IN (N\'completed\', N\'running\')
                AND [from_date] <= :to AND [to_date] >= :from
              ORDER BY [started_at] DESC',
            ['limit' => $limit, 'to' => $to, 'from' => $from],
        );

        return array_map(self::run(...), $rows);
    }

    public function recent(int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (:limit) ' . self::COLUMNS . ' FROM [finance].[ar_scan_runs] ORDER BY [started_at] DESC, [id] DESC',
            ['limit' => $limit],
        );

        return array_map(self::run(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function run(array $row): ArScanRun
    {
        return new ArScanRun(
            self::id($row['id']),
            self::when($row['started_at'], 'started_at'),
            self::whenOrNull($row['finished_at']),
            self::text($row['mailbox']),
            self::text($row['status']),
            self::whenOrNull($row['watermark']),
            self::int($row['total_fetched']),
            self::int($row['new_records']),
            self::int($row['skipped_records']),
            self::int($row['failed_enrichments']),
            self::text($row['errors']),
            $row['from_date'] === null ? null : substr(self::text($row['from_date']), 0, 10),
            $row['to_date'] === null ? null : substr(self::text($row['to_date']), 0, 10),
            $row['requested_by_user_id'] === null ? null : self::int($row['requested_by_user_id']),
            self::text($row['requested_by']),
            self::int($row['total_messages']),
            self::int($row['processed_messages']),
            self::whenOrNull($row['claim_expires_at']),
        );
    }
}
