<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\DiscoverLegacyMasterDataService;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Live\UnavailableLegacyTcRepository;
use TriCab\Core\Product\Migration\Live\UnavailableLegacyWordPressRepository;

final class DiscoverLegacyMasterDataServiceTest extends TestCase
{
    public function test_unconfigured_sources_do_not_invent_counts(): void
    {
        $service = new DiscoverLegacyMasterDataService(
            new LiveLegacySourceConfig(
                wordpressDsn: null,
                wordpressUser: null,
                wordpressPassword: null,
                wordpressTablePrefix: 'wp_',
                wordpressSslMode: null,
                waiveWordpress: false,
                tcDsn: null,
                tcUser: null,
                tcPassword: null,
                tcSslMode: null,
                waiveTc: false,
                filesRoot: null,
                waiveFiles: true,
            ),
            new UnavailableLegacyWordPressRepository(),
            new UnavailableLegacyTcRepository(),
            new InMemoryLegacySqlStatementLogger(),
        );

        $report = $service->execute();
        self::assertFalse($report->wordpressConnected);
        self::assertFalse($report->tcConnected);
        self::assertTrue($report->readOnlyGuaranteed);
        self::assertSame('NOT READY', $report->payload['migrationReadiness']['fullProductMigration']);
        self::assertSame(0.0, $report->payload['join']['matchPercent']);
    }
}
