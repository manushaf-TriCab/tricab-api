<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Odoo;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecord;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordRow;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordStore;

/**
 * `finance.odoo_records` (`create-finance-odoo-schema.sql`).
 *
 * **No query that orders selects `data_json`.** {@see self::list()} sorts on `name` and
 * `odoo_id` (an index covers the order) over the small columns, then reads the payloads of
 * those ids in a second, unordered query. The same `ORDER BY` over rows carrying the
 * `NVARCHAR(MAX)` column took 25 seconds for 1,864 vendors on AWS. A test reads the
 * query plan and fails if a sort ever carries the column again.
 *
 * Lists of ids go in as numbered parameters, a thousand to a statement, under SQL
 * Server's limit of 2,100.
 */
final class AzureSqlOdooRecordStore implements OdooRecordStore
{
    private const CHUNK = 1000;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function maxWriteDate(string $listKey): ?DateTimeImmutable
    {
        $rows = $this->connection->fetchAll(
            'SELECT MAX([write_date]) AS [newest] FROM [finance].[odoo_records] WHERE [list_key] = :list',
            ['list' => $listKey],
        );

        return $rows === [] ? null : AzureSqlValueMapper::nullableDateTime($rows[0]['newest'] ?? null);
    }

    public function existing(string $listKey, ?array $ids): array
    {
        $found = [];
        if ($ids === null) {
            $rows = $this->connection->fetchAll(
                'SELECT [odoo_id], [deleted_at] FROM [finance].[odoo_records] WHERE [list_key] = :list',
                ['list' => $listKey],
            );
            foreach ($rows as $row) {
                $found[AzureSqlValueMapper::intValue($row['odoo_id'])] = $row['deleted_at'] !== null;
            }

            return $found;
        }

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            [$placeholders, $params] = self::idList($chunk);
            $rows = $this->connection->fetchAll(
                'SELECT [odoo_id], [deleted_at] FROM [finance].[odoo_records] WHERE [list_key] = :list AND [odoo_id] IN (' . $placeholders . ')',
                ['list' => $listKey] + $params,
            );
            foreach ($rows as $row) {
                $found[AzureSqlValueMapper::intValue($row['odoo_id'])] = $row['deleted_at'] !== null;
            }
        }

        return $found;
    }

    public function insert(OdooRecordRow $row, DateTimeImmutable $syncedAt): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[odoo_records]
                ([list_key], [odoo_id], [name], [code], [company_id], [is_active], [data_json], [write_date], [deleted_at], [synced_at])
             VALUES (:list, :id, :name, :code, :company, :active, :data, :written, NULL, :synced)',
            self::params($row, $syncedAt),
        );
    }

    public function update(OdooRecordRow $row, DateTimeImmutable $syncedAt): void
    {
        $this->connection->execute(
            'UPDATE [finance].[odoo_records]
             SET [name] = :name, [code] = :code, [company_id] = :company, [is_active] = :active,
                 [data_json] = :data, [write_date] = :written, [deleted_at] = NULL, [synced_at] = :synced
             WHERE [list_key] = :list AND [odoo_id] = :id',
            self::params($row, $syncedAt),
        );
    }

    public function markRemoved(string $listKey, array $odooIds, DateTimeImmutable $at): void
    {
        $stamp = AzureSqlValueMapper::formatDateTime($at);
        foreach (array_chunk($odooIds, self::CHUNK) as $chunk) {
            [$placeholders, $params] = self::idList($chunk);
            $this->connection->execute(
                'UPDATE [finance].[odoo_records] SET [deleted_at] = :gone, [synced_at] = :synced
                 WHERE [list_key] = :list AND [odoo_id] IN (' . $placeholders . ')',
                ['gone' => $stamp, 'synced' => $stamp, 'list' => $listKey] + $params,
            );
        }
    }

    public function list(string $listKey, bool $includeInactive, ?DateTimeImmutable $updatedSince): array
    {
        $where = '[list_key] = :list';
        $params = ['list' => $listKey];
        if ($updatedSince !== null) {
            $where .= ' AND [synced_at] >= :since';
            $params['since'] = AzureSqlValueMapper::formatDateTime($updatedSince);
        } else {
            $where .= ' AND [deleted_at] IS NULL';
            if (!$includeInactive) {
                $where .= ' AND [is_active] = 1';
            }
        }

        // Step 1: the order, over the small columns only. Never [data_json] here.
        $rows = $this->connection->fetchAll(
            'SELECT [odoo_id], [is_active], [write_date], [deleted_at], [synced_at]
             FROM [finance].[odoo_records] WHERE ' . $where . ' ORDER BY [name], [odoo_id]',
            $params,
        );

        // Step 2: the payloads of those ids, unordered.
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = AzureSqlValueMapper::intValue($row['odoo_id']);
        }
        $payloads = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            [$placeholders, $idParams] = self::idList($chunk);
            foreach ($this->connection->fetchAll(
                'SELECT [odoo_id], [data_json] FROM [finance].[odoo_records] WHERE [list_key] = :list AND [odoo_id] IN (' . $placeholders . ')',
                ['list' => $listKey] + $idParams,
            ) as $payloadRow) {
                $payloads[AzureSqlValueMapper::intValue($payloadRow['odoo_id'])] = AzureSqlValueMapper::stringValue($payloadRow['data_json']);
            }
        }

        $records = [];
        foreach ($rows as $row) {
            $id = AzureSqlValueMapper::intValue($row['odoo_id']);
            /** @var array<string, mixed> $payload */
            $payload = json_decode($payloads[$id] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $records[] = new OdooRecord(
                $listKey,
                $id,
                $payload,
                AzureSqlValueMapper::boolValue($row['is_active']),
                $row['deleted_at'] !== null,
                AzureSqlValueMapper::nullableDateTime($row['write_date'] ?? null),
                AzureSqlValueMapper::requireDateTime($row['synced_at'], 'synced_at'),
            );
        }

        return $records;
    }

    public function counts(): array
    {
        $counts = array_fill_keys(OdooLists::KEYS, 0);
        foreach ($this->connection->fetchAll(
            'SELECT [list_key], COUNT(*) AS [live] FROM [finance].[odoo_records] WHERE [deleted_at] IS NULL GROUP BY [list_key]',
        ) as $row) {
            $counts[AzureSqlValueMapper::stringValue($row['list_key'])] = AzureSqlValueMapper::intValue($row['live']);
        }

        return $counts;
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function params(OdooRecordRow $row, DateTimeImmutable $syncedAt): array
    {
        return [
            'list' => $row->listKey,
            'id' => $row->odooId,
            'name' => $row->name,
            'code' => $row->code,
            'company' => $row->companyId,
            'active' => $row->isActive ? 1 : 0,
            'data' => $row->dataJson,
            'written' => $row->writeDate === null ? null : AzureSqlValueMapper::formatDateTime($row->writeDate),
            'synced' => AzureSqlValueMapper::formatDateTime($syncedAt),
        ];
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>} the placeholder list and its parameters
     */
    private static function idList(array $ids): array
    {
        $names = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $names[] = ':i' . $index;
            $params['i' . $index] = $id;
        }

        return [implode(', ', $names), $params];
    }
}
