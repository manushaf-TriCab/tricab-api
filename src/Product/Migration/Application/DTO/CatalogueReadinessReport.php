<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final class CatalogueReadinessReport
{
    /**
     * @param list<array{code: string, status: string, message: string, blocking: bool}> $gates
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $sourceConfig
     * @param list<string> $nextActions
     */
    public function __construct(
        public readonly string $overallStatus,
        public readonly array $gates,
        public readonly array $inventory,
        public readonly array $schema,
        public readonly array $sourceConfig,
        public readonly array $nextActions,
        public readonly bool $allowsLiveDryRun,
        public readonly bool $allowsSmallBatchExecute,
        public readonly bool $allowsFullCatalogueMigration,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'overallStatus' => $this->overallStatus,
            'gates' => $this->gates,
            'inventory' => $this->inventory,
            'schema' => $this->schema,
            'sourceConfig' => $this->sourceConfig,
            'nextActions' => $this->nextActions,
            'allowsLiveDryRun' => $this->allowsLiveDryRun,
            'allowsSmallBatchExecute' => $this->allowsSmallBatchExecute,
            'allowsFullCatalogueMigration' => $this->allowsFullCatalogueMigration,
        ];
    }
}
