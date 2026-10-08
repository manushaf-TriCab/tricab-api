<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Odoo;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecord;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordRow;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordStore;

/**
 * The copy held in memory: the tests', and a database-less run's. `snapshot` and
 * `restore` let a test's transaction double roll the whole copy back, as the database does.
 */
final class InMemoryOdooRecordStore implements OdooRecordStore
{
    /** @var array<string, array{row: OdooRecordRow, deletedAt: ?DateTimeImmutable, syncedAt: DateTimeImmutable}> keyed by list and id */
    private array $records = [];

    public function maxWriteDate(string $listKey): ?DateTimeImmutable
    {
        $max = null;
        foreach ($this->records as $entry) {
            $at = $entry['row']->writeDate;
            if ($entry['row']->listKey === $listKey && $at !== null && ($max === null || $at > $max)) {
                $max = $at;
            }
        }

        return $max;
    }

    public function existing(string $listKey, ?array $ids): array
    {
        $found = [];
        foreach ($this->records as $entry) {
            if ($entry['row']->listKey !== $listKey) {
                continue;
            }
            if ($ids === null || in_array($entry['row']->odooId, $ids, true)) {
                $found[$entry['row']->odooId] = $entry['deletedAt'] !== null;
            }
        }

        return $found;
    }

    public function insert(OdooRecordRow $row, DateTimeImmutable $syncedAt): void
    {
        $this->records[self::key($row->listKey, $row->odooId)] = ['row' => $row, 'deletedAt' => null, 'syncedAt' => $syncedAt];
    }

    public function update(OdooRecordRow $row, DateTimeImmutable $syncedAt): void
    {
        $this->records[self::key($row->listKey, $row->odooId)] = ['row' => $row, 'deletedAt' => null, 'syncedAt' => $syncedAt];
    }

    public function markRemoved(string $listKey, array $odooIds, DateTimeImmutable $at): void
    {
        foreach ($odooIds as $id) {
            $key = self::key($listKey, $id);
            if (isset($this->records[$key])) {
                $this->records[$key]['deletedAt'] = $at;
                $this->records[$key]['syncedAt'] = $at;
            }
        }
    }

    public function list(string $listKey, bool $includeInactive, ?DateTimeImmutable $updatedSince): array
    {
        $selected = [];
        foreach ($this->records as $entry) {
            $row = $entry['row'];
            if ($row->listKey !== $listKey) {
                continue;
            }
            if ($updatedSince !== null) {
                if ($entry['syncedAt'] < $updatedSince) {
                    continue;
                }
            } elseif ($entry['deletedAt'] !== null || (!$includeInactive && !$row->isActive)) {
                continue;
            }
            $selected[] = $entry;
        }
        usort($selected, static fn (array $a, array $b): int => [mb_strtolower($a['row']->name), $a['row']->odooId] <=> [mb_strtolower($b['row']->name), $b['row']->odooId]);

        return array_map(static function (array $entry): OdooRecord {
            $row = $entry['row'];
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->dataJson, true, 512, JSON_THROW_ON_ERROR);

            return new OdooRecord($row->listKey, $row->odooId, $payload, $row->isActive, $entry['deletedAt'] !== null, $row->writeDate, $entry['syncedAt']);
        }, $selected);
    }

    public function counts(): array
    {
        $counts = array_fill_keys(OdooLists::KEYS, 0);
        foreach ($this->records as $entry) {
            if ($entry['deletedAt'] === null) {
                $counts[$entry['row']->listKey] = ($counts[$entry['row']->listKey] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, array{row: OdooRecordRow, deletedAt: ?DateTimeImmutable, syncedAt: DateTimeImmutable}>
     */
    public function snapshot(): array
    {
        return $this->records;
    }

    /**
     * @param array<string, array{row: OdooRecordRow, deletedAt: ?DateTimeImmutable, syncedAt: DateTimeImmutable}> $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->records = $snapshot;
    }

    private static function key(string $listKey, int $odooId): string
    {
        return $listKey . '|' . $odooId;
    }
}
