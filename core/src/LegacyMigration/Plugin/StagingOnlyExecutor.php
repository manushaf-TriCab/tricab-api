<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Application\Exception\ProductionWriteRefused;
use TriCab\Core\LegacyMigration\Contract\Executor;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;

/**
 * Writes only in-memory / staging snapshots. Never identity, customer, or other production tables.
 */
final class StagingOnlyExecutor implements Executor
{
    public function execute(NormalizedRecord $record, array $mapped): array
    {
        if ($this->writesProductionTables()) {
            throw ProductionWriteRefused::because('Framework executor must not write production tables.');
        }

        return [
            'action' => 'snapshot',
            'target' => 'migration.LegacyMigrationSnapshots',
            'snapshot' => [
                'moduleCode' => $record->moduleCode,
                'legacyId' => $record->sourceIdentifier,
                'mapped' => $mapped,
            ],
        ];
    }

    public function writesProductionTables(): bool
    {
        return false;
    }
}
