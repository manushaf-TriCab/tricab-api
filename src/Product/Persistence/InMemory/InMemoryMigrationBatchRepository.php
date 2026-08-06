<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Product\Migration\Application\DTO\DryRunReport;
use TriCab\Core\Product\Migration\Application\DTO\MigrationBatchDetails;
use TriCab\Core\Product\Migration\Application\DTO\MigrationIssueDetails;
use TriCab\Core\Product\Migration\Application\Exception\MigrationBatchNotFound;
use TriCab\Core\Product\Migration\Application\Exception\MigrationIssueNotFound;
use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;

final class InMemoryMigrationBatchRepository implements MigrationBatchRepository
{
    private int $nextBatchId = 1;
    private int $rowVersion = 1;

    /** @var array<string, array<string, mixed>> */
    private array $batches = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $raw = [];
    /** @var array<string, list<array{product: CanonicalProduct, meta: array<string, mixed>}>> */
    private array $canonical = [];
    /** @var array<string, array<string, mixed>> */
    private array $issues = [];
    /** @var array<string, list<DryRunReport>> */
    private array $dryRuns = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $reconciliations = [];
    /** @var list<array<string, mixed>> */
    private array $audit = [];
    /** @var array<string, array<string, mixed>> */
    private array $entityMappings = [];
    /** @var array<string, array<string, mixed>> */
    private array $classificationMappings = [];
    /** @var array<string, array<string, mixed>> */
    private array $specificationMappings = [];
    /** @var array<string, array<string, mixed>> */
    private array $optionAliases = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $importedProducts = [];
    /** @var array<string, bool> */
    private array $manuallyEdited = [];

    public function __construct(private readonly UuidV4Generator $uuids = new UuidV4Generator())
    {
    }

    public function createBatch(array $data): MigrationBatchDetails
    {
        $publicId = $this->uuids->generate()->toString();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $row = [
            'migrationBatchId' => $this->nextBatchId++,
            'publicId' => $publicId,
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'sourceSystem' => (string) $data['sourceSystem'],
            'sourceFilter' => $data['sourceFilter'] ?? null,
            'dryRun' => (bool) ($data['dryRun'] ?? true),
            'statusCode' => 'draft',
            'createdAtUtc' => $now,
            'createdByUserId' => (int) $data['createdByUserId'],
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
        $this->raw[$publicId] = [];
        $this->canonical[$publicId] = [];
        $this->importedProducts[$publicId] = [];

        return $this->toBatch($row);
    }

    public function getBatchByPublicId(string $publicId): ?MigrationBatchDetails
    {
        $row = $this->batches[$publicId] ?? null;

        return $row === null ? null : $this->toBatch($row);
    }

    public function listBatches(?string $statusCode = null): array
    {
        $out = [];
        foreach ($this->batches as $row) {
            if ($statusCode !== null && $row['statusCode'] !== $statusCode) {
                continue;
            }
            $out[] = $this->toBatch($row);
        }

        return $out;
    }

    public function updateBatchStatus(string $publicId, string $statusCode, array $counts = [], ?array $timestamps = null): MigrationBatchDetails
    {
        $row = &$this->requireBatch($publicId);
        $row['statusCode'] = $statusCode;
        foreach (['totalRecords', 'importedRecords', 'updatedRecords', 'skippedRecords', 'warningRecords', 'failedRecords', 'manualReviewRecords'] as $k) {
            if (isset($counts[$k])) {
                $row[$k] = (int) $counts[$k];
            }
        }
        if ($timestamps !== null) {
            foreach ($timestamps as $k => $v) {
                $row[$k] = $v;
            }
        }
        $row['rowVersion'] = (string) $this->rowVersion++;

        return $this->toBatch($row);
    }

    public function upsertRaw(string $batchPublicId, array $row): void
    {
        $this->requireBatch($batchPublicId);
        $key = strtolower($row['sourceSystem'] . '|' . $row['sourceTable'] . '|' . $row['sourceIdentifier']);
        foreach ($this->raw[$batchPublicId] as $i => $existing) {
            $ek = strtolower($existing['sourceSystem'] . '|' . $existing['sourceTable'] . '|' . $existing['sourceIdentifier']);
            if ($ek === $key) {
                $this->raw[$batchPublicId][$i] = $row;

                return;
            }
        }
        $this->raw[$batchPublicId][] = $row;
    }

    public function listRaw(string $batchPublicId, ?string $recordType = null): array
    {
        $this->requireBatch($batchPublicId);
        $rows = $this->raw[$batchPublicId] ?? [];
        if ($recordType === null) {
            return $rows;
        }

        return array_values(array_filter($rows, static fn (array $r): bool => ($r['recordType'] ?? '') === $recordType));
    }

    public function upsertCanonical(string $batchPublicId, CanonicalProduct $product, array $meta = []): void
    {
        $this->requireBatch($batchPublicId);
        foreach ($this->canonical[$batchPublicId] as $i => $existing) {
            if ($existing['product']->sourceKey === $product->sourceKey) {
                $this->canonical[$batchPublicId][$i] = ['product' => $product, 'meta' => $meta];

                return;
            }
        }
        $this->canonical[$batchPublicId][] = ['product' => $product, 'meta' => $meta];
    }

    public function listCanonical(string $batchPublicId): array
    {
        $this->requireBatch($batchPublicId);

        return array_map(static fn (array $row): CanonicalProduct => $row['product'], $this->canonical[$batchPublicId] ?? []);
    }

    public function addIssue(string $batchPublicId, array $issue): MigrationIssueDetails
    {
        $this->requireBatch($batchPublicId);
        $publicId = $this->uuids->generate()->toString();
        $row = [
            'publicId' => $publicId,
            'batchPublicId' => $batchPublicId,
            'issueTypeCode' => (string) $issue['issueTypeCode'],
            'severityCode' => (string) ($issue['severityCode'] ?? 'warning'),
            'sourceSystem' => $issue['sourceSystem'] ?? null,
            'sourceTable' => $issue['sourceTable'] ?? null,
            'sourceIdentifier' => $issue['sourceIdentifier'] ?? null,
            'sourceKey' => $issue['sourceKey'] ?? null,
            'message' => (string) $issue['message'],
            'suggestedMatch' => $issue['suggestedMatch'] ?? null,
            'matchConfidence' => $issue['matchConfidence'] ?? null,
            'statusCode' => 'open',
            'resolution' => null,
            'createdAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $this->issues[$publicId] = $row;

        return $this->toIssue($row);
    }

    public function listIssues(?string $batchPublicId = null, ?string $statusCode = 'open', int $limit = 100, int $offset = 0): array
    {
        $out = [];
        foreach ($this->issues as $row) {
            if ($batchPublicId !== null && $row['batchPublicId'] !== $batchPublicId) {
                continue;
            }
            if ($statusCode !== null && $row['statusCode'] !== $statusCode) {
                continue;
            }
            $out[] = $this->toIssue($row);
        }

        return array_slice($out, $offset, $limit);
    }

    public function resolveIssue(string $issuePublicId, array $resolution, int $resolvedByUserId): MigrationIssueDetails
    {
        if (!isset($this->issues[$issuePublicId])) {
            throw MigrationIssueNotFound::forPublicId($issuePublicId);
        }
        $this->issues[$issuePublicId]['statusCode'] = (string) ($resolution['statusCode'] ?? 'resolved');
        $this->issues[$issuePublicId]['resolution'] = array_merge($resolution, [
            'resolvedByUserId' => $resolvedByUserId,
            'resolvedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return $this->toIssue($this->issues[$issuePublicId]);
    }

    public function saveDryRunReport(string $batchPublicId, DryRunReport $report): void
    {
        $this->requireBatch($batchPublicId);
        $this->dryRuns[$batchPublicId][] = $report;
    }

    public function getLatestDryRunReport(string $batchPublicId): ?DryRunReport
    {
        $list = $this->dryRuns[$batchPublicId] ?? [];

        return $list === [] ? null : $list[array_key_last($list)];
    }

    public function saveReconciliation(string $batchPublicId, array $report): array
    {
        $this->requireBatch($batchPublicId);
        $report['publicId'] = $this->uuids->generate()->toString();
        $report['createdAtUtc'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->reconciliations[$batchPublicId][] = $report;

        return $report;
    }

    public function getLatestReconciliation(string $batchPublicId): ?array
    {
        $list = $this->reconciliations[$batchPublicId] ?? [];

        return $list === [] ? null : $list[array_key_last($list)];
    }

    public function recordAudit(?string $batchPublicId, string $actionCode, ?int $actorUserId, ?string $correlationId, ?array $payload): void
    {
        if ($payload !== null) {
            foreach (array_keys($payload) as $key) {
                $lower = strtolower((string) $key);
                if (str_contains($lower, 'password') || str_contains($lower, 'token') || str_contains($lower, 'sas') || str_contains($lower, 'credential')) {
                    $payload[$key] = '***';
                }
            }
        }
        $this->audit[] = [
            'batchPublicId' => $batchPublicId,
            'actionCode' => $actionCode,
            'actorUserId' => $actorUserId,
            'correlationId' => $correlationId,
            'payload' => $payload,
            'createdAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function upsertEntityMapping(array $mapping): void
    {
        $key = strtolower($mapping['sourceSystem'] . '|' . $mapping['sourceTable'] . '|' . $mapping['sourceIdentifier']);
        $this->entityMappings[$key] = $mapping;
    }

    public function findEntityMapping(string $sourceSystem, string $sourceTable, string $sourceIdentifier): ?array
    {
        $key = strtolower($sourceSystem . '|' . $sourceTable . '|' . $sourceIdentifier);

        return $this->entityMappings[$key] ?? null;
    }

    public function listClassificationMappings(): array
    {
        return array_values($this->classificationMappings);
    }

    public function upsertClassificationMapping(string $publicId, array $data): array
    {
        if ($publicId === '' || $publicId === 'new') {
            $publicId = $this->uuids->generate()->toString();
        }
        $row = array_merge($this->classificationMappings[$publicId] ?? ['publicId' => $publicId], $data, ['publicId' => $publicId]);
        $this->classificationMappings[$publicId] = $row;

        return $row;
    }

    public function listSpecificationMappings(): array
    {
        return array_values($this->specificationMappings);
    }

    public function upsertSpecificationMapping(string $publicId, array $data): array
    {
        if ($publicId === '' || $publicId === 'new') {
            $publicId = $this->uuids->generate()->toString();
        }
        $row = array_merge($this->specificationMappings[$publicId] ?? ['publicId' => $publicId], $data, ['publicId' => $publicId]);
        $this->specificationMappings[$publicId] = $row;

        return $row;
    }

    public function listOptionAliases(): array
    {
        return array_values($this->optionAliases);
    }

    public function upsertOptionAlias(string $publicId, array $data): array
    {
        if ($publicId === '' || $publicId === 'new') {
            $publicId = $this->uuids->generate()->toString();
        }
        $row = array_merge($this->optionAliases[$publicId] ?? ['publicId' => $publicId], $data, ['publicId' => $publicId]);
        $this->optionAliases[$publicId] = $row;

        return $row;
    }

    public function executeImport(string $batchPublicId, bool $dryRun): array
    {
        $this->requireBatch($batchPublicId);
        $result = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'warning' => 0,
            'manualReview' => 0,
            'productWrites' => 0,
            'blobWrites' => 0,
        ];

        foreach ($this->canonical[$batchPublicId] ?? [] as $entry) {
            /** @var CanonicalProduct $product */
            $product = $entry['product'];
            $meta = $entry['meta'];
            if (($meta['requiresReview'] ?? false) === true || ($product->productDecisionCode ?? '') === 'manual-review') {
                ++$result['manualReview'];
                continue;
            }
            if (($product->status ?? 'draft') !== 'draft') {
                // force draft on import path conceptually
            }

            [$system, $table, $id] = $this->splitSourceKey($product->sourceKey);
            $existing = $this->findEntityMapping($system, $table !== '' ? $table : 'product', $id);
            $hash = (string) ($meta['sourceHash'] ?? '');
            if ($existing !== null && ($existing['sourceHash'] ?? null) === $hash) {
                ++$result['skipped'];
                continue;
            }

            if ($dryRun) {
                ++$result['imported'];
                continue;
            }

            $targetPublicId = $existing['targetPublicId'] ?? $this->uuids->generate()->toString();
            $action = $existing === null ? 'imported' : 'updated';
            if ($action === 'imported') {
                ++$result['imported'];
            } else {
                ++$result['updated'];
            }
            ++$result['productWrites'];

            // Media/docs would use Phase 9 Blob; only count when sourceExists and not dry-run
            foreach ($product->media as $media) {
                if ($media->sourceExists) {
                    ++$result['blobWrites'];
                } else {
                    ++$result['warning'];
                }
            }
            foreach ($product->documents as $doc) {
                if ($doc->sourceExists) {
                    ++$result['blobWrites'];
                } else {
                    ++$result['warning'];
                }
            }

            $this->importedProducts[$batchPublicId][] = [
                'sourceKey' => $product->sourceKey,
                'targetPublicId' => $targetPublicId,
                'status' => 'draft',
                'manuallyEdited' => false,
            ];
            $this->upsertEntityMapping([
                'sourceSystem' => $system,
                'sourceTable' => $table !== '' ? $table : 'product',
                'sourceIdentifier' => $id,
                'targetEntityType' => 'product',
                'targetPublicId' => $targetPublicId,
                'migrationBatchId' => $batchPublicId,
                'sourceHash' => $hash,
                'migrationStatus' => $action,
                'migratedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }

        return $result;
    }

    public function rollbackBatch(string $batchPublicId, bool $forceProtected = false): array
    {
        $this->requireBatch($batchPublicId);
        $rolled = 0;
        $protected = 0;
        $remaining = [];
        foreach ($this->importedProducts[$batchPublicId] ?? [] as $row) {
            $edited = ($row['manuallyEdited'] ?? false) === true || ($this->manuallyEdited[$row['targetPublicId']] ?? false);
            if ($edited && !$forceProtected) {
                ++$protected;
                $remaining[] = $row;
                continue;
            }
            ++$rolled;
            // soft-delete conceptually: drop from active imports; keep mapping marked rolled-back
            [$system, $table, $id] = $this->splitSourceKey((string) $row['sourceKey']);
            $mapping = $this->findEntityMapping($system, $table !== '' ? $table : 'product', $id);
            if ($mapping !== null) {
                $mapping['migrationStatus'] = 'rolled-back';
                $this->upsertEntityMapping($mapping);
            }
        }
        $this->importedProducts[$batchPublicId] = $remaining;

        return ['rolledBack' => $rolled, 'protected' => $protected];
    }

    /** @return list<array<string, mixed>> */
    public function auditEvents(): array
    {
        return $this->audit;
    }

    /** @return list<array<string, mixed>> */
    public function importedProductsFor(string $batchPublicId): array
    {
        return $this->importedProducts[$batchPublicId] ?? [];
    }

    public function markManuallyEdited(string $targetPublicId): void
    {
        $this->manuallyEdited[$targetPublicId] = true;
        foreach ($this->importedProducts as $batchId => $rows) {
            foreach ($rows as $i => $row) {
                if (($row['targetPublicId'] ?? null) === $targetPublicId) {
                    $this->importedProducts[$batchId][$i]['manuallyEdited'] = true;
                }
            }
        }
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function splitSourceKey(string $sourceKey): array
    {
        $parts = explode(':', $sourceKey);
        if (count($parts) >= 3) {
            return [$parts[0], $parts[1], implode(':', array_slice($parts, 2))];
        }
        if (count($parts) === 2) {
            return [$parts[0], '', $parts[1]];
        }

        return ['unknown', '', $sourceKey];
    }

    /** @return array<string, mixed> */
    private function &requireBatch(string $publicId): array
    {
        if (!isset($this->batches[$publicId])) {
            throw MigrationBatchNotFound::forPublicId($publicId);
        }

        return $this->batches[$publicId];
    }

    /** @param array<string, mixed> $row */
    private function toBatch(array $row): MigrationBatchDetails
    {
        return new MigrationBatchDetails(
            migrationBatchId: (int) $row['migrationBatchId'],
            publicId: (string) $row['publicId'],
            name: (string) $row['name'],
            description: isset($row['description']) ? (string) $row['description'] : null,
            sourceSystem: (string) $row['sourceSystem'],
            sourceFilter: is_array($row['sourceFilter'] ?? null) ? $row['sourceFilter'] : null,
            dryRun: (bool) $row['dryRun'],
            statusCode: (string) $row['statusCode'],
            createdAtUtc: (string) $row['createdAtUtc'],
            createdByUserId: (int) $row['createdByUserId'],
            startedAtUtc: isset($row['startedAtUtc']) ? (string) $row['startedAtUtc'] : null,
            completedAtUtc: isset($row['completedAtUtc']) ? (string) $row['completedAtUtc'] : null,
            rolledBackAtUtc: isset($row['rolledBackAtUtc']) ? (string) $row['rolledBackAtUtc'] : null,
            rolledBackByUserId: isset($row['rolledBackByUserId']) ? (int) $row['rolledBackByUserId'] : null,
            totalRecords: (int) $row['totalRecords'],
            importedRecords: (int) $row['importedRecords'],
            updatedRecords: (int) $row['updatedRecords'],
            skippedRecords: (int) $row['skippedRecords'],
            warningRecords: (int) $row['warningRecords'],
            failedRecords: (int) $row['failedRecords'],
            manualReviewRecords: (int) $row['manualReviewRecords'],
            rowVersion: (string) $row['rowVersion'],
        );
    }

    /** @param array<string, mixed> $row */
    private function toIssue(array $row): MigrationIssueDetails
    {
        return new MigrationIssueDetails(
            publicId: (string) $row['publicId'],
            batchPublicId: (string) $row['batchPublicId'],
            issueTypeCode: (string) $row['issueTypeCode'],
            severityCode: (string) $row['severityCode'],
            sourceSystem: isset($row['sourceSystem']) ? (string) $row['sourceSystem'] : null,
            sourceTable: isset($row['sourceTable']) ? (string) $row['sourceTable'] : null,
            sourceIdentifier: isset($row['sourceIdentifier']) ? (string) $row['sourceIdentifier'] : null,
            sourceKey: isset($row['sourceKey']) ? (string) $row['sourceKey'] : null,
            message: (string) $row['message'],
            suggestedMatch: is_array($row['suggestedMatch'] ?? null) ? $row['suggestedMatch'] : null,
            matchConfidence: isset($row['matchConfidence']) ? (string) $row['matchConfidence'] : null,
            statusCode: (string) $row['statusCode'],
            resolution: is_array($row['resolution'] ?? null) ? $row['resolution'] : null,
            createdAtUtc: (string) $row['createdAtUtc'],
        );
    }
}
