<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final class MigrationBatchDetails
{
    /**
     * @param array<string, mixed>|null $sourceFilter
     */
    public function __construct(
        public readonly int $migrationBatchId,
        public readonly string $publicId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $sourceSystem,
        public readonly ?array $sourceFilter,
        public readonly bool $dryRun,
        public readonly string $statusCode,
        public readonly string $createdAtUtc,
        public readonly int $createdByUserId,
        public readonly ?string $startedAtUtc,
        public readonly ?string $completedAtUtc,
        public readonly ?string $rolledBackAtUtc,
        public readonly ?int $rolledBackByUserId,
        public readonly int $totalRecords,
        public readonly int $importedRecords,
        public readonly int $updatedRecords,
        public readonly int $skippedRecords,
        public readonly int $warningRecords,
        public readonly int $failedRecords,
        public readonly int $manualReviewRecords,
        public readonly string $rowVersion,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'name' => $this->name,
            'description' => $this->description,
            'sourceSystem' => $this->sourceSystem,
            'sourceFilter' => $this->sourceFilter,
            'dryRun' => $this->dryRun,
            'statusCode' => $this->statusCode,
            'createdAtUtc' => $this->createdAtUtc,
            'createdByUserId' => $this->createdByUserId,
            'startedAtUtc' => $this->startedAtUtc,
            'completedAtUtc' => $this->completedAtUtc,
            'rolledBackAtUtc' => $this->rolledBackAtUtc,
            'rolledBackByUserId' => $this->rolledBackByUserId,
            'totalRecords' => $this->totalRecords,
            'importedRecords' => $this->importedRecords,
            'updatedRecords' => $this->updatedRecords,
            'skippedRecords' => $this->skippedRecords,
            'warningRecords' => $this->warningRecords,
            'failedRecords' => $this->failedRecords,
            'manualReviewRecords' => $this->manualReviewRecords,
            'rowVersion' => $this->rowVersion,
        ];
    }
}
