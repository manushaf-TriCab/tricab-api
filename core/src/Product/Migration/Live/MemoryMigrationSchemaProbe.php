<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

/** In-memory mode: schema script is available; runtime uses InMemory repository. */
final class MemoryMigrationSchemaProbe implements MigrationSchemaProbe
{
    public function probe(): array
    {
        return [
            'applied' => true,
            'missingTables' => [],
            'checkedTables' => [
                'migration.MigrationBatches',
                'migration.LegacyProductRaw',
                'migration.CanonicalProducts',
                'migration.MigrationIssues',
                'migration.LegacyProductMappings',
            ],
            'persistenceMode' => 'in-memory',
        ];
    }
}
