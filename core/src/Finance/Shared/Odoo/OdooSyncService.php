<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;
use TriCab\Core\Shared\Time\Clock;

/**
 * Copies the four Odoo lists into `finance.odoo_records`, with the rules of
 * `_work-portal-entra-20261005/server/odoo_sync.py` (`run_sync`, `_sync_list`):
 *
 * - **Only a full sweep removes.** A record deleted outright from Odoo, or one that stopped
 *   being a vendor, changes no `write_date` an incremental query can see, so only a full
 *   sweep, which reads every record, can notice a record is gone. A removed record keeps
 *   its row with `deleted_at` set, so an invoice that already points at it still resolves.
 * - **An incremental pass** reads records written at or after the newest `write_date` held
 *   (`>=`, not `>`: `write_date` has one-second resolution and the write is idempotent). A
 *   list with nothing held is read in full.
 * - **All four lists are written in one transaction.** A failure in any leaves the copy as
 *   it was, and the run is recorded as failed with the reason.
 * - **Two runs never overlap**, and a run stuck for 15 minutes is abandoned first. The
 *   guarantee is the table's (one row may be `running`).
 *
 * The portal's third mode, `auto`, is not carried: the daily job always sweeps in full, and
 * a person asking for a sync chooses full or incremental (Phase 1, Q5).
 */
final class OdooSyncService
{
    public const STALE_AFTER_SECONDS = 900;

    public const ODOO_DATETIME = 'Y-m-d H:i:s';

    /** A guard against a server that ignores the paging and would never end. */
    public const MAX_NAME_LENGTH = 400;

    public const MAX_CODE_LENGTH = 100;

    public function __construct(
        private readonly OdooClient $client,
        private readonly OdooRecordStore $records,
        private readonly OdooSyncRuns $runs,
        private readonly ApplicationTransaction $transaction,
        private readonly Clock $clock,
        private readonly OdooSyncConfig $config,
    ) {
    }

    /**
     * Runs one sync and returns its record, succeeded or failed (a failure is data, not an
     * exception, so a caller can show why).
     *
     * @throws OdooSyncBusy another run is in progress
     * @throws InvalidArgumentException the mode or the trigger is not one of the known ones
     */
    public function run(string $mode, string $triggeredBy, ?int $actorUserId = null): OdooSyncRun
    {
        if (!in_array($mode, [OdooSyncRun::MODE_FULL, OdooSyncRun::MODE_INCREMENTAL], true)) {
            throw new InvalidArgumentException('The mode must be full or incremental.');
        }
        if (!in_array($triggeredBy, [OdooSyncRun::TRIGGER_SCHEDULE, OdooSyncRun::TRIGGER_ADMIN], true)) {
            throw new InvalidArgumentException('The trigger must be schedule or admin.');
        }

        $now = $this->clock->now();
        $this->runs->abandonStale($now->modify('-' . self::STALE_AFTER_SECONDS . ' seconds'), $now);
        $id = bin2hex(random_bytes(16));
        if (!$this->runs->start($id, $mode, $triggeredBy, $actorUserId, $now)) {
            throw new OdooSyncBusy();
        }

        $counts = [];
        $error = null;
        try {
            /** @var array<string, array{fetched: int, added: int, updated: int, removed: int}> $counts */
            $counts = $this->transaction->run(function () use ($mode, $now): array {
                $counts = [];
                foreach (OdooLists::all() as $spec) {
                    $counts[$spec->key] = $this->syncList($spec, $mode === OdooSyncRun::MODE_FULL, $now);
                }

                return $counts;
            });
            $status = OdooSyncRun::STATUS_SUCCEEDED;
        } catch (Throwable $failure) {
            $status = OdooSyncRun::STATUS_FAILED;
            $error = mb_substr($failure instanceof OdooError ? $failure->getMessage() : $failure::class . ': ' . $failure->getMessage(), 0, 1000);
        }
        $this->runs->finish($id, $status, $counts, $error, $this->clock->now());

        return $this->runs->find($id) ?? new OdooSyncRun($id, $mode, $triggeredBy, $status, $counts, $error, $now, $this->clock->now(), $actorUserId);
    }

    /**
     * @return array{fetched: int, added: int, updated: int, removed: int}
     */
    private function syncList(OdooListSpec $spec, bool $full, DateTimeImmutable $now): array
    {
        $domain = $spec->domainFor($this->config->companyIds);
        if (!$full) {
            $cursor = $this->records->maxWriteDate($spec->key);
            if ($cursor === null) {
                $full = true;
            } else {
                // >= and not >: write_date has one-second resolution, and the write is idempotent.
                $domain[] = ['write_date', '>=', $cursor->setTimezone(new DateTimeZone('UTC'))->format(self::ODOO_DATETIME)];
            }
        }

        $rows = $this->client->searchRead($spec->model, $domain, $spec->fields);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = self::odooId($row);
        }
        $existing = $this->records->existing($spec->key, $full ? null : $ids);

        $added = $updated = $removed = 0;
        foreach ($rows as $row) {
            $record = $this->rowFor($spec, $row);
            if (array_key_exists($record->odooId, $existing)) {
                $this->records->update($record, $now);
                ++$updated;
            } else {
                $this->records->insert($record, $now);
                ++$added;
            }
        }

        if ($full) {
            $seen = array_flip($ids);
            $gone = [];
            foreach ($existing as $odooId => $isDeleted) {
                if (!$isDeleted && !isset($seen[$odooId])) {
                    $gone[] = $odooId;
                }
            }
            if ($gone !== []) {
                $this->records->markRemoved($spec->key, $gone, $now);
                $removed = count($gone);
            }
        }

        return ['fetched' => count($rows), 'added' => $added, 'updated' => $updated, 'removed' => $removed];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowFor(OdooListSpec $spec, array $row): OdooRecordRow
    {
        $odooId = self::odooId($row);
        $payload = $spec->payload($row);
        $name = is_string($payload['name'] ?? null) ? $payload['name'] : '';
        $code = null;
        if ($spec->codeField !== null) {
            $value = $payload[$spec->codeField] ?? '';
            $code = is_string($value) && $value !== '' ? mb_substr($value, 0, self::MAX_CODE_LENGTH) : null;
        }
        $companyId = $spec->isCompanies()
            ? $odooId
            : (is_int($payload['company_id'] ?? null) ? $payload['company_id'] : null);

        return new OdooRecordRow(
            $spec->key,
            $odooId,
            mb_substr($name, 0, self::MAX_NAME_LENGTH),
            $code,
            $companyId,
            ($row['active'] ?? true) !== false && ($row['active'] ?? true) !== 0,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::writeDate($row['write_date'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function odooId(array $row): int
    {
        $id = $row['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new OdooError('Odoo returned a record with no id.');
        }

        return (int) $id;
    }

    private static function writeDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || strlen($value) < 19) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . self::ODOO_DATETIME, substr($value, 0, 19), new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }

    /**
     * What a caller reads: live and active records by default; with `$updatedSince`, every
     * record synced since, archived and deleted included.
     *
     * @return list<OdooRecord>
     *
     * @throws InvalidArgumentException the list is not one of the four
     */
    public function list(string $listKey, bool $includeInactive = false, ?DateTimeImmutable $updatedSince = null): array
    {
        if (OdooLists::find($listKey) === null) {
            throw new InvalidArgumentException('Unknown reference list.');
        }

        return $this->records->list($listKey, $includeInactive, $updatedSince);
    }

    /**
     * The cursor for a caller's next `updated_since`. A sync stamps its rows with its start
     * time but commits at the end, so the cursor never passes the start of one still running
     * (the portal's `read_cursor`): a row committed late would otherwise be missed.
     */
    public function readCursor(): DateTimeImmutable
    {
        $cursor = $this->clock->now()->modify('-1 second');
        $running = $this->runs->oldestRunningStart();

        return $running !== null && $running < $cursor ? $running : $cursor;
    }

    public function lastSuccess(): ?OdooSyncRun
    {
        return $this->runs->lastSuccess();
    }

    /**
     * Counts, the last success, recent runs and the key's expiry: what an administrator
     * checks (the portal's `status_payload`).
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $today = $this->clock->now();
        $daysLeft = $this->config->keyDaysLeft($today);

        return [
            'company_ids' => $this->config->companyIds,
            'key_expires' => $this->config->keyExpires !== '' ? $this->config->keyExpires : null,
            'key_days_left' => $daysLeft,
            'key_expires_soon' => $this->config->keyExpiresSoon($today),
            'counts' => $this->records->counts(),
            'last_success' => $this->runs->lastSuccess()?->toArray(),
            'recent_runs' => array_map(static fn (OdooSyncRun $run): array => $run->toArray(), $this->runs->recent(10)),
        ];
    }

    public function config(): OdooSyncConfig
    {
        return $this->config;
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
