<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Odoo;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRun;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRuns;

/**
 * `finance.odoo_sync_runs` (`create-finance-odoo-schema.sql`). A run starts with one
 * `INSERT ... WHERE NOT EXISTS (a running row)`, and the filtered unique index that allows
 * one `running` row settles two that race past the check; the loser reads back and finds
 * its id absent. As everywhere in this schema, no query that orders also selects a long
 * text column: the ids are ordered first and the rows fetched by id.
 */
final class AzureSqlOdooSyncRuns implements OdooSyncRuns
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function abandonStale(DateTimeImmutable $cutoff, DateTimeImmutable $at): int
    {
        $params = ['cutoff' => AzureSqlValueMapper::formatDateTime($cutoff)];
        $stale = $this->connection->fetchAll(
            'SELECT [id] FROM [finance].[odoo_sync_runs] WHERE [status] = \'running\' AND [started_at] < :cutoff',
            $params,
        );
        if ($stale === []) {
            return 0;
        }
        $this->connection->execute(
            'UPDATE [finance].[odoo_sync_runs] SET [status] = \'abandoned\', [finished_at] = :at
             WHERE [status] = \'running\' AND [started_at] < :cutoff',
            ['at' => AzureSqlValueMapper::formatDateTime($at)] + $params,
        );

        return count($stale);
    }

    public function start(string $id, string $mode, string $triggeredBy, ?int $actorUserId, DateTimeImmutable $at): bool
    {
        try {
            $this->connection->execute(
                'INSERT INTO [finance].[odoo_sync_runs] ([id], [mode], [triggered_by], [actor_user_id], [status], [counts_json], [started_at])
                 SELECT :id, :mode, :trigger, :actor, \'running\', N\'{}\', :at
                 WHERE NOT EXISTS (SELECT 1 FROM [finance].[odoo_sync_runs] WHERE [status] = \'running\')',
                ['id' => $id, 'mode' => $mode, 'trigger' => $triggeredBy, 'actor' => $actorUserId, 'at' => AzureSqlValueMapper::formatDateTime($at)],
            );
        } catch (DatabaseUnavailable) {
            // Raced: the unique index refused a second running row. The read below says whether the run is ours.
        }

        return $this->find($id) !== null;
    }

    public function finish(string $id, string $status, array $counts, ?string $error, DateTimeImmutable $at): bool
    {
        $this->connection->execute(
            'UPDATE [finance].[odoo_sync_runs]
             SET [status] = :status, [counts_json] = :counts, [error_message] = :error, [finished_at] = :at
             WHERE [id] = :id AND [status] = \'running\'',
            [
                'status' => $status,
                'counts' => json_encode($counts, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
                'error' => $error === null ? null : mb_substr($error, 0, 1000),
                'at' => AzureSqlValueMapper::formatDateTime($at),
                'id' => $id,
            ],
        );
        $run = $this->find($id);

        return $run !== null && $run->status === $status;
    }

    public function find(string $id): ?OdooSyncRun
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [mode], [triggered_by], [actor_user_id], [status], [counts_json], [error_message], [started_at], [finished_at]
             FROM [finance].[odoo_sync_runs] WHERE [id] = :id',
            ['id' => $id],
        );

        return $rows === [] ? null : self::run($rows[0]);
    }

    public function lastSuccess(?string $mode = null): ?OdooSyncRun
    {
        $where = '[status] = \'succeeded\'';
        $params = [];
        if ($mode !== null) {
            $where .= ' AND [mode] = :mode';
            $params['mode'] = $mode;
        }
        $ids = $this->connection->fetchAll(
            'SELECT TOP (1) [id] FROM [finance].[odoo_sync_runs] WHERE ' . $where . ' ORDER BY [started_at] DESC',
            $params,
        );

        return $ids === [] ? null : $this->find(AzureSqlValueMapper::stringValue($ids[0]['id']));
    }

    public function recent(int $limit): array
    {
        $ids = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, min(100, $limit)) . ') [id] FROM [finance].[odoo_sync_runs] ORDER BY [started_at] DESC',
        );
        $runs = [];
        foreach ($ids as $row) {
            $run = $this->find(AzureSqlValueMapper::stringValue($row['id']));
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    public function oldestRunningStart(): ?DateTimeImmutable
    {
        $rows = $this->connection->fetchAll('SELECT MIN([started_at]) AS [oldest] FROM [finance].[odoo_sync_runs] WHERE [status] = \'running\'');

        return $rows === [] ? null : AzureSqlValueMapper::nullableDateTime($rows[0]['oldest'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function run(array $row): OdooSyncRun
    {
        /** @var array<string, array{fetched: int, added: int, updated: int, removed: int}> $counts */
        $counts = json_decode(AzureSqlValueMapper::stringValue($row['counts_json']), true, 512, JSON_THROW_ON_ERROR);

        return new OdooSyncRun(
            trim(AzureSqlValueMapper::stringValue($row['id'])),
            AzureSqlValueMapper::stringValue($row['mode']),
            AzureSqlValueMapper::stringValue($row['triggered_by']),
            AzureSqlValueMapper::stringValue($row['status']),
            $counts,
            AzureSqlValueMapper::nullableString($row['error_message'] ?? null),
            AzureSqlValueMapper::requireDateTime($row['started_at'], 'started_at'),
            AzureSqlValueMapper::nullableDateTime($row['finished_at'] ?? null),
            AzureSqlValueMapper::nullableInt($row['actor_user_id'] ?? null),
        );
    }
}
