<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Contract;

use TriCab\Core\Product\Migration\Application\DTO\DryRunReport;
use TriCab\Core\Product\Migration\Application\DTO\MigrationBatchDetails;
use TriCab\Core\Product\Migration\Application\DTO\MigrationIssueDetails;
use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;

interface MigrationBatchRepository
{
    /** @param array<string, mixed> $data */
    public function createBatch(array $data): MigrationBatchDetails;

    public function getBatchByPublicId(string $publicId): ?MigrationBatchDetails;

    /** @return list<MigrationBatchDetails> */
    public function listBatches(?string $statusCode = null): array;

    /**
     * @param array<string, int> $counts
     * @param array<string, mixed>|null $timestamps
     */
    public function updateBatchStatus(string $publicId, string $statusCode, array $counts = [], ?array $timestamps = null): MigrationBatchDetails;

    /** @param array<string, mixed> $row */
    public function upsertRaw(string $batchPublicId, array $row): void;

    /** @return list<array<string, mixed>> */
    public function listRaw(string $batchPublicId, ?string $recordType = null): array;

    /** @param array<string, mixed> $meta */
    public function upsertCanonical(string $batchPublicId, CanonicalProduct $product, array $meta = []): void;

    /** @return list<CanonicalProduct> */
    public function listCanonical(string $batchPublicId): array;

    /** @param array<string, mixed> $issue */
    public function addIssue(string $batchPublicId, array $issue): MigrationIssueDetails;

    /** @return list<MigrationIssueDetails> */
    public function listIssues(?string $batchPublicId = null, ?string $statusCode = 'open', int $limit = 100, int $offset = 0): array;

    /** @param array<string, mixed> $resolution */
    public function resolveIssue(string $issuePublicId, array $resolution, int $resolvedByUserId): MigrationIssueDetails;

    public function saveDryRunReport(string $batchPublicId, DryRunReport $report): void;

    public function getLatestDryRunReport(string $batchPublicId): ?DryRunReport;

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function saveReconciliation(string $batchPublicId, array $report): array;

    /** @return array<string, mixed>|null */
    public function getLatestReconciliation(string $batchPublicId): ?array;

    /** @param array<string, mixed>|null $payload */
    public function recordAudit(?string $batchPublicId, string $actionCode, ?int $actorUserId, ?string $correlationId, ?array $payload): void;

    /** @param array<string, mixed> $mapping */
    public function upsertEntityMapping(array $mapping): void;

    /** @return array<string, mixed>|null */
    public function findEntityMapping(string $sourceSystem, string $sourceTable, string $sourceIdentifier): ?array;

    /** @return list<array<string, mixed>> */
    public function listClassificationMappings(): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsertClassificationMapping(string $publicId, array $data): array;

    /** @return list<array<string, mixed>> */
    public function listSpecificationMappings(): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsertSpecificationMapping(string $publicId, array $data): array;

    /** @return list<array<string, mixed>> */
    public function listOptionAliases(): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsertOptionAlias(string $publicId, array $data): array;

    /**
     * @return array{imported: int, updated: int, skipped: int, failed: int, warning: int, manualReview: int, productWrites: int, blobWrites: int}
     */
    public function executeImport(string $batchPublicId, bool $dryRun): array;

    /**
     * @return array{rolledBack: int, protected: int}
     */
    public function rollbackBatch(string $batchPublicId, bool $forceProtected = false): array;
}
