<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Readiness;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\BuildCatalogueMappingReadinessService;
use TriCab\Core\Product\Migration\Application\Service\DiscoverLegacyMasterDataService;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Live\UnavailableLegacyTcRepository;
use TriCab\Core\Product\Migration\Live\UnavailableLegacyWordPressRepository;
use TriCab\Core\Product\Migration\Readiness\MigrationReadinessStatus;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;

final class CatalogueMappingReadinessServiceTest extends TestCase
{
    public function testOfflineRunProducesNotReadyVerdictWithoutLegacyWrites(): void
    {
        $config = new LiveLegacySourceConfig(
            wordpressDsn: null,
            wordpressUser: null,
            wordpressPassword: null,
            wordpressTablePrefix: 'wp_',
            wordpressSslMode: 'disabled',
            waiveWordpress: true,
            tcDsn: null,
            tcUser: null,
            tcPassword: null,
            tcSslMode: 'disabled',
            waiveTc: true,
            filesRoot: null,
            waiveFiles: true,
            phase12PilotApproved: false,
        );
        $logger = new InMemoryLegacySqlStatementLogger();
        $wp = new UnavailableLegacyWordPressRepository();
        $tc = new UnavailableLegacyTcRepository();
        $discovery = new DiscoverLegacyMasterDataService($config, $wp, $tc, $logger);
        $service = new BuildCatalogueMappingReadinessService(
            $config,
            $wp,
            $tc,
            $logger,
            $discovery,
            new StyleCodeNormalizer(),
        );

        $report = $service->execute([
            'families' => [['code' => 'CABLE', 'name' => 'Cables']],
            'categories' => [],
            'series' => [],
            'units' => [],
            'products' => [],
            'catalogueNodes' => [],
            'frontendCategories' => ['Accessories'],
        ]);
        $data = $report->toArray();

        self::assertSame('NOT READY', $data['fullCatalogueDryRun']);
        self::assertSame(0, $data['legacyWrites']);
        self::assertSame('NO', $data['productMigration']);
        self::assertSame('NO', $data['pricingMigration']);
        self::assertSame(MigrationReadinessStatus::BLOCKED, $data['payload']['specificationUnits']['status']);
        self::assertSame(MigrationReadinessStatus::BLOCKED, $data['payload']['media']['status']);
        self::assertNotEmpty($data['payload']['classification']['families']);
        self::assertSame('ACCESSORIES', $data['payload']['classification']['families'][1]['proposedTarget']);
    }
}
