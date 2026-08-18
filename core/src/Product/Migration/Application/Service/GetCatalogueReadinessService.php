<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Application\DTO\CatalogueReadinessReport;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Live\LiveInventoryProbe;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Live\MigrationSchemaProbe;

final class GetCatalogueReadinessService
{
    public function __construct(
        private readonly LiveLegacySourceConfig $sourceConfig,
        private readonly LiveInventoryProbe $inventoryProbe,
        private readonly MigrationSchemaProbe $schemaProbe,
        private readonly MigrationBatchRepository $batches,
        private readonly bool $azureSqlMode = false,
        private readonly bool $blobEnabled = false,
        private readonly string $persistenceMode = 'in-memory',
    ) {
    }

    public function execute(): CatalogueReadinessReport
    {
        $schema = $this->schemaProbe->probe();
        $inventory = $this->inventoryProbe->probe($this->sourceConfig);

        // Avoid hard 503 when Azure SQL migration tables are not applied yet.
        $classMappings = [];
        $specMappings = [];
        $openIssues = 0;
        if ($schema['applied']) {
            $classMappings = $this->batches->listClassificationMappings();
            $specMappings = $this->batches->listSpecificationMappings();
            $openIssues = count($this->batches->listIssues(null, 'open', 1000, 0));
        }

        $mappedClass = count(array_filter($classMappings, static fn (array $m): bool => ($m['statusCode'] ?? '') === 'mapped'));
        $mappedSpec = count(array_filter($specMappings, static fn (array $m): bool => ($m['statusCode'] ?? '') === 'mapped'));

        $gates = [];
        $gates[] = $this->gate(
            'phase12PilotApproved',
            $this->sourceConfig->phase12PilotApproved,
            'Phase 12 fixture pilot approved',
            'Phase 12 pilot not marked approved',
            true,
        );
        $gates[] = $this->gate(
            'migrationSchemaApplied',
            $schema['applied'],
            'Migration schema tables present',
            'Missing migration tables: ' . implode(', ', $schema['missingTables']),
            true,
        );
        $persistenceOk = !$this->azureSqlMode || $this->persistenceMode === 'azure-sql';
        $gates[] = $this->gate(
            'azureMigrationPersistence',
            $persistenceOk,
            'Migration persistence mode: ' . $this->persistenceMode,
            'Azure SQL mode requires AzureSqlMigrationBatchRepository',
            $this->azureSqlMode,
        );

        $wpOk = $this->sourceConfig->wordpressConfigured() || $this->sourceConfig->waiveWordpress;
        $gates[] = $this->gate(
            'liveWordpressConfigured',
            $wpOk,
            $this->sourceConfig->wordpressConfigured() ? 'WordPress read-only config present' : 'WordPress waived',
            'Set LEGACY_WP_* or LEGACY_SOURCE_WAIVE_WP=true',
            true,
        );
        $tcOk = $this->sourceConfig->tcConfigured() || $this->sourceConfig->waiveTc;
        $gates[] = $this->gate(
            'liveTcConfigured',
            $tcOk,
            $this->sourceConfig->tcConfigured() ? 'tc read-only config present' : 'tc waived',
            'Set LEGACY_TC_* or LEGACY_SOURCE_WAIVE_TC=true',
            true,
        );
        $filesOk = $this->sourceConfig->filesConfigured() || $this->sourceConfig->waiveFiles;
        $gates[] = $this->gate(
            'liveFilesConfigured',
            $filesOk,
            $this->sourceConfig->filesConfigured() ? 'Legacy files root readable' : 'Files waived',
            'Set LEGACY_FILES_ROOT or LEGACY_SOURCE_WAIVE_FILES=true',
            true,
        );

        $inventoryStatus = $inventory['status'];
        $inventoryReady = $inventoryStatus !== 'PENDING_LIVE_INVENTORY'
            || ($this->sourceConfig->waiveWordpress && $this->sourceConfig->waiveTc);
        $gates[] = $this->gate(
            'liveInventoryCaptured',
            $inventoryReady,
            'Live inventory status: ' . $inventoryStatus,
            'PENDING_LIVE_INVENTORY — provide read-only credentials and re-run readiness',
            false,
        );
        $gates[] = $this->gate(
            'classificationMappingsSeeded',
            $mappedClass > 0,
            $mappedClass . ' mapped classification mapping(s)',
            'Map at least one legacy classification before live execute',
            false,
        );
        $gates[] = $this->gate(
            'specificationMappingsSeeded',
            $mappedSpec > 0,
            $mappedSpec . ' mapped specification field mapping(s)',
            'Map core fields (e.g. od → OUTER_DIAMETER) before live execute',
            false,
        );
        $gates[] = $this->gate(
            'blobInfrastructureReady',
            $this->blobEnabled || $this->sourceConfig->waiveFiles,
            $this->blobEnabled ? 'Phase 9 blob storage enabled' : 'Blob not required while files waived',
            'Enable Phase 9 blob mode before media/document copy',
            false,
        );
        $gates[] = $this->gate(
            'openIssuePressure',
            $openIssues < 100,
            $openIssues . ' open migration issue(s)',
            'Too many open issues; resolve review queue before scaling batches',
            false,
        );

        $blockingFailed = array_values(array_filter($gates, static fn (array $g): bool => $g['blocking'] && $g['status'] !== 'ready'));
        $nonBlockingFailed = array_values(array_filter($gates, static fn (array $g): bool => !$g['blocking'] && $g['status'] !== 'ready'));

        $allowsLiveDryRun = count($blockingFailed) === 0;
        $allowsSmallBatch = $allowsLiveDryRun && count($nonBlockingFailed) === 0;
        if ($allowsSmallBatch) {
            $overall = 'ready-for-small-batch-execute';
        } elseif ($allowsLiveDryRun) {
            $overall = 'ready-for-live-dry-run';
        } else {
            $overall = 'blocked';
        }

        $next = [];
        foreach ($gates as $gate) {
            if ($gate['status'] !== 'ready') {
                $next[] = $gate['code'] . ': ' . $gate['message'];
            }
        }
        if ($next === []) {
            $next[] = 'Ready for a small controlled live dry-run batch. Full catalogue still requires separate approval.';
        }
        $next[] = 'Full catalogue migration remains out of scope until explicitly approved.';

        return new CatalogueReadinessReport(
            overallStatus: $overall,
            gates: $gates,
            inventory: $inventory,
            schema: $schema,
            sourceConfig: $this->sourceConfig->toPublicArray(),
            nextActions: $next,
            allowsLiveDryRun: $allowsLiveDryRun,
            allowsSmallBatchExecute: $allowsSmallBatch,
            allowsFullCatalogueMigration: false,
        );
    }

    /**
     * @return array{code: string, status: string, message: string, blocking: bool}
     */
    private function gate(string $code, bool $ok, string $readyMessage, string $failMessage, bool $blocking): array
    {
        return [
            'code' => $code,
            'status' => $ok ? 'ready' : 'not-ready',
            'message' => $ok ? $readyMessage : $failMessage,
            'blocking' => $blocking,
        ];
    }
}
