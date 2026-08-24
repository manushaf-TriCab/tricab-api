<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\MasterData;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\PlanProductMigrationMasterDataService;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Live\UnavailableLegacyTcRepository;

final class PlanProductMigrationMasterDataServiceTest extends TestCase
{
    public function testDryRunProposesUnitsAndAccessoriesWithoutMutations(): void
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
        );
        $service = new PlanProductMigrationMasterDataService($config, new UnavailableLegacyTcRepository());

        $phase126 = [
            'baseline' => ['productReady' => 24],
            'classification' => [
                'families' => [],
                'categories' => [
                    ['termId' => 1, 'name' => 'Flexible', 'nameDecoded' => 'Flexible', 'conceptClass' => 'internal_category_candidate'],
                    ['termId' => 2, 'name' => '110V', 'nameDecoded' => '110V', 'conceptClass' => 'voltage'],
                    ['termId' => 3, 'name' => 'News', 'nameDecoded' => 'News', 'conceptClass' => 'obsolete'],
                ],
                'series' => [
                    ['proposedSeriesCode' => 'FT', 'legacyStyleCode' => 'FT', 'family' => 'CABLE', 'variantCount' => 10],
                    ['proposedSeriesCode' => 'B3C, B3M', 'legacyStyleCode' => 'B3C, B3M', 'family' => 'CABLE'],
                ],
            ],
            'specifications' => ['items' => []],
            'dvFieldMappings' => [
                'rows' => [
                    ['style' => 'A01', 'sourceField' => 'd1', 'legacyLabel' => 'Pins', 'proposedDefinition' => 'Pins', 'status' => 'SUGGESTED'],
                    ['style' => '*', 'sourceField' => 'd1', 'legacyLabel' => 'x', 'proposedDefinition' => 'x', 'status' => 'REVIEW'],
                ],
            ],
            'options' => ['proposals' => [['canonicalOption' => 'BK', 'usageCount' => 1]]],
            'standards' => ['values' => [['rawValue' => 'IEC', 'canonicalCandidate' => 'IEC', 'usageCount' => 2]]],
            'approvals' => ['values' => []],
            'catalogueNavigation' => ['proposedNodes' => [['name' => 'Special Applications']]],
            'accessoryIdentity' => ['exactProductCodeJoin' => 0, 'accessorySpecsRows' => 10],
            'tproExclusions' => ['tproOnlyRows' => 81247],
            'productIdentity' => ['counts' => ['REVIEW' => 1, 'SOURCE_ONLY' => 1, 'INVALID' => 1]],
        ];

        $report = $service->dryRun(
            $phase126,
            [
                'families' => [['code' => 'CABLE', 'name' => 'Cables'], ['code' => 'PLUG', 'name' => 'Plugs']],
                'categories' => [],
                'series' => [],
                'specificationDefinitions' => [],
                'specificationGroups' => [['code' => 'ELEC', 'name' => 'Electrical']],
                'units' => [],
                'catalogueNodes' => [['code' => 'N1', 'name' => 'Accessories']],
                'specificationUnitsPresent' => false,
                'productCount' => 118,
            ],
            [
                'server' => 'tricab-mel.database.windows.net',
                'database' => 'tc',
                'environmentLabel' => 'NON-PRODUCTION',
            ],
        );

        $data = $report->toArray();
        self::assertSame('dry-run', $data['mode']);
        self::assertFalse($data['applied']);
        self::assertSame(0, $data['legacyWrites']);
        self::assertSame(0, $data['productWrites']);
        self::assertSame('SCHEMA_NOT_APPLIED', $data['payload']['schemaInspection']['rootCause']['classification']);
        self::assertSame('NON-PRODUCTION', $data['payload']['environmentGate']['status']);
        self::assertGreaterThan(0, $data['payload']['dryRunSummary']['proposedForApprovalCount']);
        self::assertSame(0, $data['payload']['plan']['categories']['create']);
        self::assertSame(0, $data['payload']['plan']['series']['create']);
        self::assertSame('KEEP_SEPARATE', $data['payload']['plugVsAccessories']['PLUG_family']);
        self::assertSame('EXPLICIT_MAPPING_REQUIRED', $data['payload']['accessoryIdentity']['outcome']);
        self::assertTrue($data['payload']['approvalBoundary']['stopAfterDryRun']);
    }

    public function testUncertainEnvironmentBlocksApplyGate(): void
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
        );
        $service = new PlanProductMigrationMasterDataService($config);
        $report = $service->dryRun(
            ['classification' => ['categories' => [], 'series' => []], 'dvFieldMappings' => ['rows' => []], 'options' => ['proposals' => []], 'standards' => ['values' => []], 'approvals' => ['values' => []], 'catalogueNavigation' => ['proposedNodes' => []], 'specifications' => ['items' => []], 'accessoryIdentity' => [], 'tproExclusions' => [], 'productIdentity' => ['counts' => []]],
            ['specificationUnitsPresent' => true, 'families' => [], 'categories' => [], 'series' => [], 'units' => [['code' => 'M']], 'catalogueNodes' => [], 'specificationDefinitions' => [], 'specificationGroups' => []],
            ['server' => '', 'database' => '', 'environmentLabel' => 'UNKNOWN'],
        );
        self::assertSame('UNKNOWN', $report->toArray()['payload']['environmentGate']['status']);
        self::assertFalse($report->toArray()['payload']['environmentGate']['allowApply']);
    }
}
