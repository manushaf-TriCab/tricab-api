<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use DateTimeImmutable;

/**
 * `finance.odoo_records`: the platform's copy of the four lists.
 *
 * **No method that orders rows selects the payload.** Ordering a result that carries an
 * `NVARCHAR(MAX)` column took 25 seconds for 1,864 vendors on AWS (6 Oct 2026), so
 * {@see self::list()} orders on the name and id columns alone and fetches the payloads
 * afterwards, by key.
 */
interface OdooRecordStore
{
    /** The newest `write_date` held for the list, or null when it holds none (an incremental pass then reads everything). */
    public function maxWriteDate(string $listKey): ?DateTimeImmutable;

    /**
     * Which of the list's records the copy already holds, as `odoo id => is deleted`.
     * With `$ids` only those; with null, every one.
     *
     * @param list<int>|null $ids
     * @return array<int, bool>
     */
    public function existing(string $listKey, ?array $ids): array;

    public function insert(OdooRecordRow $row, DateTimeImmutable $syncedAt): void;

    /** Replaces the row's columns and payload and clears `deleted_at`: a record that came back is live again. */
    public function update(OdooRecordRow $row, DateTimeImmutable $syncedAt): void;

    /**
     * Marks records as gone from Odoo (`deleted_at`, `synced_at`); their rows stay.
     *
     * @param list<int> $odooIds
     */
    public function markRemoved(string $listKey, array $odooIds, DateTimeImmutable $at): void;

    /**
     * Live records by default (not deleted, and active unless `$includeInactive`). With
     * `$updatedSince`, **every** record synced at or after that time, archived and deleted
     * ones included, so a cache can refresh without refetching the list. Ordered by name
     * (case-insensitively, as the database's collation does) then Odoo id.
     *
     * @return list<OdooRecord>
     */
    public function list(string $listKey, bool $includeInactive, ?DateTimeImmutable $updatedSince): array;

    /**
     * Live (not deleted) records per list key, a zero for a list with none.
     *
     * @return array<string, int>
     */
    public function counts(): array;
}
