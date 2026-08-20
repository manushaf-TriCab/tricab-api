<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationStore;
use TriCab\Core\LegacyMigration\Support\ConflictDecision;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;

final class InMemoryLegacyMigrationStore implements LegacyMigrationStore
{
    private int $rowVersion = 1;

    /** @var array<string, array<string, mixed>> */
    private array $sources = [];
    /** @var array<string, array<string, mixed>> */
    private array $batches = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $items = [];
    /** @var list<array<string, mixed>> */
    private array $logs = [];
    /** @var array<string, array<string, mixed>> */
    private array $conflicts = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $warnings = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $validation = [];
    /** @var list<array<string, mixed>> */
    private array $reports = [];
    /** @var list<array<string, mixed>> */
    private array $rollbacks = [];
    /** @var array<string, array<string, mixed>> */
    private array $jobs = [];
    /** @var list<array<string, mixed>> */
    private array $execution = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $snapshots = [];
    /** @var array<string, array{owner: string, until: int}> */
    private array $locks = [];
    /** @var list<array<string, mixed>> */
    private array $statistics = [];
    /** @var array<string, string> */
    private array $settings = [
        'allowFixtureExtract' => 'true',
        'requireApproval' => 'true',
        'refuseProductionWrites' => 'true',
    ];
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $raw = [];

    public function __construct(private readonly UuidV4Generator $uuids = new UuidV4Generator())
    {
        foreach (SourceCatalog::sources() as $source) {
            $this->sources[$source['code']] = [
                'code' => $source['code'],
                'label' => $source['label'],
                'kind' => $source['kind'],
                'health' => $source['kind'] === 'harness' ? 'healthy' : 'unconfigured',
                'version' => null,
                'lastSyncAtUtc' => null,
                'recordCounts' => [],
                'errors' => [],
                'connection' => ['configured' => false, 'readOnly' => true],
            ];
        }
    }

    public function listSources(): array
    {
        return array_values($this->sources);
    }

    public function upsertSource(array $row): array
    {
        $code = (string) ($row['code'] ?? '');
        $current = $this->sources[$code] ?? ['code' => $code];
        $this->sources[$code] = array_merge($current, $row);

        return $this->sources[$code];
    }

    public function getSource(string $code): ?array
    {
        return $this->sources[$code] ?? null;
    }

    public function createBatch(array $data): array
    {
        $publicId = $this->uuids->generate()->toString();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $row = [
            'publicId' => $publicId,
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'moduleCode' => (string) $data['moduleCode'],
            'sourceSystem' => (string) $data['sourceSystem'],
            'sourceFilter' => $data['sourceFilter'] ?? null,
            'statusCode' => (string) ($data['statusCode'] ?? 'created'),
            'createdAtUtc' => $now,
            'createdByUserId' => (int) $data['createdByUserId'],
            'approvedAtUtc' => null,
            'approvedByUserId' => null,
            'startedAtUtc' => null,
            'completedAtUtc' => null,
            'rolledBackAtUtc' => null,
            'rolledBackByUserId' => null,
            'totalRecords' => 0,
            'importedRecords' => 0,
            'updatedRecords' => 0,
            'skippedRecords' => 0,
            'warningRecords' => 0,
            'failedRecords' => 0,
            'manualReviewRecords' => 0,
            'rowVersion' => (string) $this->rowVersion++,
        ];
        $this->batches[$publicId] = $row;
        $this->items[$publicId] = [];

        return $row;
    }

    public function getBatch(string $publicId): ?array
    {
        return $this->batches[$publicId] ?? null;
    }

    public function listBatches(?string $statusCode = null, ?string $moduleCode = null): array
    {
        $rows = array_values($this->batches);
        if ($statusCode !== null) {
            $rows = array_values(array_filter($rows, static fn (array $b): bool => ($b['statusCode'] ?? '') === $statusCode));
        }
        if ($moduleCode !== null) {
            $rows = array_values(array_filter($rows, static fn (array $b): bool => ($b['moduleCode'] ?? '') === $moduleCode));
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['createdAtUtc'], (string) $a['createdAtUtc']));

        return $rows;
    }

    public function updateBatch(string $publicId, array $patch): array
    {
        $this->batches[$publicId] = array_merge($this->batches[$publicId], $patch);
        $this->batches[$publicId]['rowVersion'] = (string) $this->rowVersion++;

        return $this->batches[$publicId];
    }

    public function replaceItems(string $batchPublicId, array $items): void
    {
        $out = [];
        foreach ($items as $item) {
            $id = (string) ($item['publicId'] ?? $this->uuids->generate()->toString());
            $out[] = array_merge($item, [
                'publicId' => $id,
                'batchPublicId' => $batchPublicId,
            ]);
        }
        $this->items[$batchPublicId] = $out;
    }

    public function listItems(string $batchPublicId): array
    {
        return $this->items[$batchPublicId] ?? [];
    }

    public function updateItem(string $itemPublicId, array $patch): void
    {
        foreach ($this->items as $batchId => $items) {
            foreach ($items as $i => $item) {
                if (($item['publicId'] ?? '') === $itemPublicId) {
                    $this->items[$batchId][$i] = array_merge($item, $patch);
                    return;
                }
            }
        }
    }

    public function addLog(array $row): void
    {
        $this->logs[] = array_merge([
            'durationMs' => 0,
        ], $row, [
            'publicId' => $this->uuids->generate()->toString(),
        ]);
    }

    public function listLogs(?string $batchPublicId = null, int $limit = 200): array
    {
        $rows = $this->logs;
        if ($batchPublicId !== null) {
            $rows = array_values(array_filter($rows, static fn (array $l): bool => ($l['batchPublicId'] ?? null) === $batchPublicId));
        }
        $rows = array_reverse($rows);

        return array_slice($rows, 0, $limit);
    }

    public function replaceConflicts(string $batchPublicId, array $conflicts): void
    {
        foreach ($this->conflicts as $id => $row) {
            if (($row['batchPublicId'] ?? '') === $batchPublicId) {
                unset($this->conflicts[$id]);
            }
        }
        foreach ($conflicts as $conflict) {
            $id = (string) ($conflict['publicId'] ?? $this->uuids->generate()->toString());
            $this->conflicts[$id] = array_merge($conflict, [
                'publicId' => $id,
                'batchPublicId' => $batchPublicId,
                'statusCode' => $conflict['statusCode'] ?? ConflictDecision::statusForDecision($conflict['decisionCode'] ?? null),
            ]);
        }
    }

    public function updateConflict(string $publicId, array $patch): void
    {
        if (!isset($this->conflicts[$publicId])) {
            throw new \InvalidArgumentException('Conflict not found.');
        }
        $this->conflicts[$publicId] = array_merge($this->conflicts[$publicId], $patch);
    }

    public function listConflicts(?string $batchPublicId = null): array
    {
        $rows = array_values($this->conflicts);
        if ($batchPublicId !== null) {
            $rows = array_values(array_filter($rows, static fn (array $c): bool => ($c['batchPublicId'] ?? '') === $batchPublicId));
        }

        return $rows;
    }

    public function replaceWarnings(string $batchPublicId, array $warnings): void
    {
        $this->warnings[$batchPublicId] = $warnings;
    }

    public function listWarnings(string $batchPublicId): array
    {
        return $this->warnings[$batchPublicId] ?? [];
    }

    public function replaceValidation(string $batchPublicId, array $results): void
    {
        $this->validation[$batchPublicId] = $results;
    }

    public function listValidation(string $batchPublicId): array
    {
        return $this->validation[$batchPublicId] ?? [];
    }

    public function saveReport(array $row): array
    {
        $row['publicId'] = $this->uuids->generate()->toString();
        $row['createdAtUtc'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->reports[] = $row;

        return $row;
    }

    public function listReports(?string $batchPublicId = null): array
    {
        $rows = $this->reports;
        if ($batchPublicId !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['batchPublicId'] ?? '') === $batchPublicId));
        }

        return $rows;
    }

    public function addRollback(array $row): void
    {
        $this->rollbacks[] = array_merge($row, [
            'publicId' => $this->uuids->generate()->toString(),
            'createdAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function listRollback(string $batchPublicId): array
    {
        return array_values(array_filter($this->rollbacks, static fn (array $r): bool => ($r['batchPublicId'] ?? '') === $batchPublicId));
    }

    public function addJob(array $row): array
    {
        $id = $this->uuids->generate()->toString();
        $this->jobs[$id] = array_merge($row, [
            'publicId' => $id,
            'createdAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return $this->jobs[$id];
    }

    public function updateJob(string $publicId, array $patch): array
    {
        $this->jobs[$publicId] = array_merge($this->jobs[$publicId], $patch);

        return $this->jobs[$publicId];
    }

    public function listJobs(?string $batchPublicId = null): array
    {
        $rows = array_values($this->jobs);
        if ($batchPublicId !== null) {
            $rows = array_values(array_filter($rows, static fn (array $j): bool => ($j['batchPublicId'] ?? '') === $batchPublicId));
        }

        return $rows;
    }

    public function addExecution(array $row): void
    {
        $this->execution[] = array_merge($row, ['publicId' => $this->uuids->generate()->toString()]);
    }

    public function listExecution(string $batchPublicId): array
    {
        return array_values(array_filter($this->execution, static fn (array $e): bool => ($e['batchPublicId'] ?? '') === $batchPublicId));
    }

    public function saveSnapshot(array $row): void
    {
        $batchId = (string) $row['batchPublicId'];
        $this->snapshots[$batchId][] = array_merge($row, ['publicId' => $this->uuids->generate()->toString()]);
    }

    public function listSnapshots(string $batchPublicId): array
    {
        return $this->snapshots[$batchPublicId] ?? [];
    }

    public function deleteSnapshots(string $batchPublicId): int
    {
        $count = count($this->snapshots[$batchPublicId] ?? []);
        unset($this->snapshots[$batchPublicId]);

        return $count;
    }

    public function tryLock(string $batchPublicId, string $owner, int $ttlSeconds): bool
    {
        $now = time();
        $lock = $this->locks[$batchPublicId] ?? null;
        if ($lock !== null && $lock['until'] > $now && $lock['owner'] !== $owner) {
            return false;
        }
        $this->locks[$batchPublicId] = ['owner' => $owner, 'until' => $now + $ttlSeconds];

        return true;
    }

    public function releaseLock(string $batchPublicId, string $owner): void
    {
        $lock = $this->locks[$batchPublicId] ?? null;
        if ($lock !== null && $lock['owner'] === $owner) {
            unset($this->locks[$batchPublicId]);
        }
    }

    public function recordStatistic(array $row): void
    {
        $this->statistics[] = $row;
    }

    public function statisticsOverview(): array
    {
        $success = 0;
        $fail = 0;
        $duration = 0;
        $volume = 0;
        foreach ($this->statistics as $row) {
            if (!empty($row['success'])) {
                ++$success;
            } else {
                ++$fail;
            }
            $duration += (int) ($row['durationMs'] ?? 0);
            $volume += (int) ($row['recordCount'] ?? 0);
        }
        $n = max(1, $success + $fail);

        return [
            'successRate' => $success === 0 && $fail === 0 ? 0 : (int) round(100 * $success / $n),
            'averageExecutionMs' => (int) round($duration / max(1, count($this->statistics))),
            'dataVolume' => $volume,
            'executions' => count($this->statistics),
        ];
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        return $this->settings[$key] ?? $default;
    }

    public function setSetting(string $key, string $value): void
    {
        $this->settings[$key] = $value;
    }

    public function allSettings(): array
    {
        return $this->settings;
    }

    public function upsertRaw(string $tableCode, array $row): void
    {
        $batch = (string) $row['batchPublicId'];
        $legacyId = (string) $row['legacyId'];
        $this->raw[$tableCode][$batch . ':' . $legacyId] = $row;
    }

    public function listRaw(string $tableCode, string $batchPublicId): array
    {
        $out = [];
        foreach ($this->raw[$tableCode] ?? [] as $row) {
            if (($row['batchPublicId'] ?? '') === $batchPublicId) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
