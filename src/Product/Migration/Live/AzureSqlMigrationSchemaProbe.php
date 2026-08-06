<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

/**
 * Checks required Phase 12 migration tables exist. Does not create them.
 *
 * @phpstan-type TableChecker callable(string): bool
 */
final class AzureSqlMigrationSchemaProbe implements MigrationSchemaProbe
{
    private const REQUIRED = [
        'migration.MigrationBatches',
        'migration.LegacyProductRaw',
        'migration.CanonicalProducts',
        'migration.MigrationIssues',
        'migration.ClassificationMappings',
        'migration.SpecificationFieldMappings',
        'migration.SpecificationOptionAliases',
        'migration.LegacyProductMappings',
        'migration.MigrationReconciliationReports',
        'migration.MigrationAuditEvents',
        'migration.MigrationDryRunReports',
    ];

    /** @param TableChecker $tableExists */
    public function __construct(private readonly mixed $tableExists)
    {
    }

    public function probe(): array
    {
        $missing = [];
        foreach (self::REQUIRED as $table) {
            if (!($this->tableExists)($table)) {
                $missing[] = $table;
            }
        }

        return [
            'applied' => $missing === [],
            'missingTables' => $missing,
            'checkedTables' => self::REQUIRED,
            'persistenceMode' => 'azure-sql',
        ];
    }
}
