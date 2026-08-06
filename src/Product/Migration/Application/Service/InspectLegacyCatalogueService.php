<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Application\DTO\LegacyCatalogueInspectionReport;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Live\LegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

final class InspectLegacyCatalogueService
{
    public function __construct(
        private readonly LiveLegacySourceConfig $config,
        private readonly ?LegacyWordPressRepository $wordpress,
        private readonly ?LegacyTcRepository $tc,
        private readonly LegacySqlStatementLogger $sqlLogger,
    ) {
    }

    public function execute(): LegacyCatalogueInspectionReport
    {
        $notes = [
            'Legacy databases are inspected with SELECT/SHOW/DESCRIBE/EXPLAIN only.',
            'Azure SQL migration staging is the only allowed write target for migration.',
            'Dry-run must not create Products, Variants, Blobs, or publish.',
        ];
        $wp = [
            'configured' => $this->config->wordpressConfigured(),
            'waived' => $this->config->waiveWordpress,
            'tables' => [],
            'columns' => [],
            'productCounts' => [],
        ];
        $tc = [
            'configured' => $this->config->tcConfigured(),
            'waived' => $this->config->waiveTc,
            'tables' => [],
            'columns' => [],
            'rowCounts' => [],
        ];

        $wpConnected = false;
        $tcConnected = false;
        if ($this->wordpress !== null && $this->config->wordpressConfigured()) {
            $wp = array_merge($wp, $this->wordpress->inspectSchema());
            $wpConnected = true;
        } else {
            $notes[] = 'WordPress live connection not active; set LEGACY_WP_* or waive for non-live envs.';
        }
        if ($this->tc !== null && $this->config->tcConfigured()) {
            $tc = array_merge($tc, $this->tc->inspectSchema());
            $tcConnected = true;
        } else {
            $notes[] = 'tc live connection not active; set LEGACY_TC_* or waive for non-live envs.';
        }

        return new LegacyCatalogueInspectionReport(
            generatedAtUtc: gmdate('Y-m-d\TH:i:s\Z'),
            wordpressConnected: $wpConnected,
            tcConnected: $tcConnected,
            wordpress: $wp,
            tc: $tc,
            sqlLog: array_map(static fn ($e) => $e->toArray(), $this->sqlLogger->entries()),
            notes: $notes,
            readOnlyGuaranteed: true,
        );
    }
}
