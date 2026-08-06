<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\InMemory\InMemoryMigrationBatchRepository;
use TriCab\Core\Product\Migration\Application\Service\GetCatalogueReadinessService;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Live\MemoryMigrationSchemaProbe;
use TriCab\Core\Product\Migration\Live\NullLiveInventoryProbe;

final class CatalogueReadinessServiceTest extends TestCase
{
    public function test_blocked_without_live_sources_when_not_waived(): void
    {
        $report = $this->service(waive: false)->execute();
        self::assertSame('blocked', $report->overallStatus);
        self::assertFalse($report->allowsLiveDryRun);
        self::assertFalse($report->allowsFullCatalogueMigration);
        self::assertArrayNotHasKey('wordpressPassword', $report->sourceConfig);
        self::assertTrue($report->sourceConfig['phase12PilotApproved']);
    }

    public function test_ready_for_live_dry_run_when_sources_waived(): void
    {
        $report = $this->service(waive: true)->execute();
        self::assertTrue($report->allowsLiveDryRun);
        self::assertSame('ready-for-live-dry-run', $report->overallStatus);
        self::assertFalse($report->allowsSmallBatchExecute);
        self::assertFalse($report->allowsFullCatalogueMigration);
    }

    public function test_small_batch_when_mappings_seeded(): void
    {
        $batches = new InMemoryMigrationBatchRepository();
        $batches->upsertClassificationMapping('new', [
            'sourceSystem' => 'wordpress',
            'legacyClassificationValue' => 'FT',
            'statusCode' => 'mapped',
        ]);
        $batches->upsertSpecificationMapping('new', [
            'sourceSystem' => 'tc',
            'sourceTable' => 'products',
            'sourceField' => 'od',
            'targetDefinitionCode' => 'OUTER_DIAMETER',
            'statusCode' => 'mapped',
        ]);
        $service = new GetCatalogueReadinessService(
            sourceConfig: $this->config(true),
            inventoryProbe: new NullLiveInventoryProbe(),
            schemaProbe: new MemoryMigrationSchemaProbe(),
            batches: $batches,
            azureSqlMode: false,
            blobEnabled: false,
            persistenceMode: 'in-memory',
        );
        $report = $service->execute();
        self::assertTrue($report->allowsSmallBatchExecute);
        self::assertSame('ready-for-small-batch-execute', $report->overallStatus);
    }

    public function test_azure_mode_requires_azure_persistence(): void
    {
        $service = new GetCatalogueReadinessService(
            sourceConfig: $this->config(true),
            inventoryProbe: new NullLiveInventoryProbe(),
            schemaProbe: new MemoryMigrationSchemaProbe(),
            batches: new InMemoryMigrationBatchRepository(),
            azureSqlMode: true,
            blobEnabled: false,
            persistenceMode: 'in-memory',
        );
        $report = $service->execute();
        self::assertSame('blocked', $report->overallStatus);
        self::assertFalse($report->allowsLiveDryRun);
    }

    private function service(bool $waive): GetCatalogueReadinessService
    {
        return new GetCatalogueReadinessService(
            sourceConfig: $this->config($waive),
            inventoryProbe: new NullLiveInventoryProbe(),
            schemaProbe: new MemoryMigrationSchemaProbe(),
            batches: new InMemoryMigrationBatchRepository(),
            azureSqlMode: false,
            blobEnabled: false,
            persistenceMode: 'in-memory',
        );
    }

    private function config(bool $waive): LiveLegacySourceConfig
    {
        return new LiveLegacySourceConfig(
            wordpressDsn: null,
            wordpressUser: null,
            wordpressPassword: null,
            wordpressTablePrefix: 'wp_',
            waiveWordpress: $waive,
            tcDsn: null,
            tcUser: null,
            tcPassword: null,
            waiveTc: $waive,
            filesRoot: null,
            waiveFiles: $waive,
            phase12PilotApproved: true,
        );
    }
}
