<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

interface LegacyMigrationStore
{
    /** @return list<array<string, mixed>> */
    public function listSources(): array;

    /** @param array<string, mixed> $row */
    public function upsertSource(array $row): array;

    public function getSource(string $code): ?array;

    /** @param array<string, mixed> $data */
    public function createBatch(array $data): array;

    public function getBatch(string $publicId): ?array;

    /** @return list<array<string, mixed>> */
    public function listBatches(?string $statusCode = null, ?string $moduleCode = null): array;

    /** @param array<string, mixed> $patch */
    public function updateBatch(string $publicId, array $patch): array;

    /** @param list<array<string, mixed>> $items */
    public function replaceItems(string $batchPublicId, array $items): void;

    /** @return list<array<string, mixed>> */
    public function listItems(string $batchPublicId): array;

    /** @param array<string, mixed> $patch */
    public function updateItem(string $itemPublicId, array $patch): void;

    /** @param array<string, mixed> $row */
    public function addLog(array $row): void;

    /** @return list<array<string, mixed>> */
    public function listLogs(?string $batchPublicId = null, int $limit = 200): array;

    /** @param list<array<string, mixed>> $conflicts */
    public function replaceConflicts(string $batchPublicId, array $conflicts): void;

    /** @param array<string, mixed> $patch */
    public function updateConflict(string $publicId, array $patch): void;

    /** @return list<array<string, mixed>> */
    public function listConflicts(?string $batchPublicId = null): array;

    /** @param list<array<string, mixed>> $warnings */
    public function replaceWarnings(string $batchPublicId, array $warnings): void;

    /** @return list<array<string, mixed>> */
    public function listWarnings(string $batchPublicId): array;

    /** @param list<array<string, mixed>> $results */
    public function replaceValidation(string $batchPublicId, array $results): void;

    /** @return list<array<string, mixed>> */
    public function listValidation(string $batchPublicId): array;

    /** @param array<string, mixed> $row */
    public function saveReport(array $row): array;

    /** @return list<array<string, mixed>> */
    public function listReports(?string $batchPublicId = null): array;

    /** @param array<string, mixed> $row */
    public function addRollback(array $row): void;

    /** @return list<array<string, mixed>> */
    public function listRollback(string $batchPublicId): array;

    /** @param array<string, mixed> $row */
    public function addJob(array $row): array;

    /** @param array<string, mixed> $patch */
    public function updateJob(string $publicId, array $patch): array;

    /** @return list<array<string, mixed>> */
    public function listJobs(?string $batchPublicId = null): array;

    /** @param array<string, mixed> $row */
    public function addExecution(array $row): void;

    /** @return list<array<string, mixed>> */
    public function listExecution(string $batchPublicId): array;

    /** @param array<string, mixed> $row */
    public function saveSnapshot(array $row): void;

    /** @return list<array<string, mixed>> */
    public function listSnapshots(string $batchPublicId): array;

    public function deleteSnapshots(string $batchPublicId): int;

    public function tryLock(string $batchPublicId, string $owner, int $ttlSeconds): bool;

    public function releaseLock(string $batchPublicId, string $owner): void;

    /** @param array<string, mixed> $row */
    public function recordStatistic(array $row): void;

    /** @return array<string, mixed> */
    public function statisticsOverview(): array;

    public function getSetting(string $key, ?string $default = null): ?string;

    public function setSetting(string $key, string $value): void;

    /** @return array<string, string> */
    public function allSettings(): array;

    /** @param array<string, mixed> $row */
    public function upsertRaw(string $tableCode, array $row): void;

    /** @return list<array<string, mixed>> */
    public function listRaw(string $tableCode, string $batchPublicId): array;
}
