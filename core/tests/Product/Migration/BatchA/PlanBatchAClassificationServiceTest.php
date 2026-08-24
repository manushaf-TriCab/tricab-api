<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchA;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\PlanBatchAClassificationService;
use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\ClassificationNameNormalizer;
use TriCab\Core\Product\Migration\Classification\LegacyTermSemanticClassifier;
use TriCab\Core\Product\Migration\Classification\LegacyTermSemanticType;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;

final class PlanBatchAClassificationServiceTest extends TestCase
{
    public function testNormalizerDecodesEntitiesAndCollapsesWhitespace(): void
    {
        $n = new ClassificationNameNormalizer();
        self::assertSame('power and control', $n->normalize('Power &amp; Control'));
        self::assertSame('vfd vsd', $n->normalize('VFD / VSD'));
        self::assertSame('110v', $n->normalize('  110V  '));
    }

    public function testSemanticClassifierSeparatesVoltageAndNavigation(): void
    {
        $c = new LegacyTermSemanticClassifier();
        self::assertSame(
            LegacyTermSemanticType::VOLTAGE,
            $c->classify(['name' => '110V', 'nameDecoded' => '110V'])['semanticType'],
        );
        self::assertSame(
            LegacyTermSemanticType::CATALOGUE_NAVIGATION,
            $c->classify(['name' => 'Lugs', 'nameDecoded' => 'Lugs', 'conceptClass' => 'customer_navigation'])['semanticType'],
        );
        self::assertSame(
            LegacyTermSemanticType::REGION,
            $c->classify(['name' => 'NZ', 'nameDecoded' => 'NZ'])['semanticType'],
        );
        self::assertSame(
            LegacyTermSemanticType::PRODUCT_CATEGORY,
            $c->classify(['name' => 'Glands', 'nameDecoded' => 'Glands', 'conceptClass' => 'internal_category_candidate'])['semanticType'],
        );
    }

    public function testCategoryDecisionsAndDuplicateDetection(): void
    {
        $service = new PlanBatchAClassificationService();
        $report = $service->dryRun(
            [
                'classification' => [
                    'categories' => [
                        ['termId' => 1, 'name' => '110V', 'nameDecoded' => '110V', 'productUsageCount' => 9, 'conceptClass' => 'voltage'],
                        ['termId' => 2, 'name' => '110V', 'nameDecoded' => '110V', 'productUsageCount' => 6, 'conceptClass' => 'voltage'],
                        ['termId' => 3, 'name' => 'VFD / VSD', 'nameDecoded' => 'VFD / VSD', 'productUsageCount' => 3, 'conceptClass' => 'internal_category_candidate'],
                        ['termId' => 4, 'name' => 'VFD / VSD', 'nameDecoded' => 'VFD / VSD', 'productUsageCount' => 1, 'conceptClass' => 'internal_category_candidate'],
                        ['termId' => 5, 'name' => 'News', 'nameDecoded' => 'News', 'conceptClass' => 'obsolete'],
                        ['termId' => 6, 'name' => 'Glands', 'nameDecoded' => 'Glands', 'productUsageCount' => 5, 'conceptClass' => 'internal_category_candidate'],
                    ],
                    'series' => [
                        ['legacyStyleCode' => 'BV', 'family' => 'CABLE', 'productTitle' => 'RI', 'variantCount' => 10],
                        ['legacyStyleCode' => 'B3C, B3M', 'family' => 'CABLE', 'productTitle' => 'bad'],
                        ['legacyStyleCode' => '10', 'family' => 'CABLE', 'productTitle' => 'numeric'],
                        ['legacyStyleCode' => 'TC-FLEX', 'family' => 'CABLE', 'productTitle' => 'Flex'],
                    ],
                ],
                'productIdentity' => [
                    'items' => [
                        ['legacyPostId' => 1, 'postType' => 'cable', 'title' => 'A', 'styleCode' => 'BV'],
                    ],
                ],
            ],
            [
                'families' => [
                    ['code' => 'CABLE', 'name' => 'Cables', 'publicId' => 'f1'],
                    ['code' => 'ACCESSORIES', 'name' => 'Accessories', 'publicId' => 'f2'],
                    ['code' => 'PLUG', 'name' => 'Plugs', 'publicId' => 'f3'],
                ],
                'categories' => [
                    ['code' => 'VFD', 'name' => 'VFD / VSD', 'familyCode' => 'CABLE', 'publicId' => 'c1'],
                ],
                'series' => [
                    ['code' => 'TC-FLEX', 'name' => 'TriCab Flex', 'publicId' => 's1'],
                ],
                'productCount' => 118,
                'variantCount' => 4178,
                'catalogueNodeCount' => 97,
            ],
            ['server' => 'tricab-mel', 'database' => 'tc', 'environmentLabel' => 'NON-PRODUCTION'],
        );

        $data = $report->toArray();
        self::assertSame('REVIEW + DRY-RUN', $data['mode']);
        self::assertFalse($data['applied']);
        self::assertSame(0, $data['legacyWrites']);
        self::assertSame(0, $data['productWrites']);
        self::assertSame(0, $data['variantWrites']);
        self::assertSame(0, $data['catalogueWrites']);

        $cat = $data['payload']['categories']['counts'];
        self::assertSame(1, $cat[CategoryDecisionState::APPROVED_MAP_EXISTING]);
        self::assertSame(0, $cat[CategoryDecisionState::APPROVED_CREATE]);
        self::assertGreaterThanOrEqual(2, $cat[CategoryDecisionState::NOT_A_PRODUCT_CATEGORY]);
        self::assertSame(1, $cat[CategoryDecisionState::REJECT]);

        $dup = $data['payload']['duplicates'];
        self::assertNotEmpty($dup);
        $voltageDup = null;
        foreach ($dup as $row) {
            if (($row['normalizedName'] ?? '') === '110v') {
                $voltageDup = $row;
            }
        }
        self::assertNotNull($voltageDup);
        self::assertSame(LegacyTermSemanticType::VOLTAGE, $voltageDup['semanticType']);
        self::assertSame(CategoryDecisionState::NOT_A_PRODUCT_CATEGORY, $voltageDup['decision']);

        $ser = $data['payload']['series']['counts'];
        self::assertSame(1, $ser[SeriesDecisionState::APPROVED_MAP_EXISTING]);
        self::assertSame(1, $ser[SeriesDecisionState::INVALID]);
        self::assertGreaterThanOrEqual(1, $ser[SeriesDecisionState::REVIEW]);

        $glands = null;
        foreach ($data['payload']['categories']['items'] as $item) {
            if ((int) $item['termId'] === 6) {
                $glands = $item;
            }
        }
        self::assertNotNull($glands);
        self::assertSame('ACCESSORIES', $glands['proposedFamily']);
        self::assertSame(CategoryDecisionState::REVIEW, $glands['decision']);

        self::assertSame(0, $data['payload']['dryRunTargetPlan']['products']['mutations']);
        self::assertTrue($data['payload']['approvalBoundary']['stopAfterDryRun']);
    }

    public function testSeriesRequiresApprovedCategoryForCreate(): void
    {
        $service = new PlanBatchAClassificationService();
        $report = $service->dryRun(
            [
                'classification' => [
                    'categories' => [],
                    'series' => [
                        ['legacyStyleCode' => 'FT', 'family' => 'CABLE', 'category' => 'FLEX-POWER', 'productTitle' => 'Flex'],
                    ],
                ],
            ],
            [
                'families' => [['code' => 'CABLE', 'name' => 'Cables']],
                'categories' => [['code' => 'FLEX-POWER', 'name' => 'Flexible Power', 'familyCode' => 'CABLE']],
                'series' => [],
            ],
            ['environmentLabel' => 'NON-PRODUCTION'],
            [
                'series:FT' => [
                    'decision' => SeriesDecisionState::APPROVED_CREATE,
                    'categoryCode' => 'FLEX-POWER',
                    'reason' => 'operator_force_create',
                ],
            ],
        );
        $items = $report->toArray()['payload']['series']['items'];
        self::assertSame(SeriesDecisionState::CONFLICT, $items[0]['decision']);
        self::assertSame('series_create_requires_approved_category', $items[0]['reason']);
    }

    public function testBulkApprovalSafety(): void
    {
        $service = new PlanBatchAClassificationService();
        $ok = $service->validateBulkApproval('category', [
            'rule' => 'VOLTAGE',
            'decision' => CategoryDecisionState::NOT_A_PRODUCT_CATEGORY,
            'family' => '',
        ], [
            ['semanticType' => 'VOLTAGE', 'proposedFamily' => null, 'conflict' => false],
            ['semanticType' => 'VOLTAGE', 'proposedFamily' => null, 'conflict' => false],
            ['semanticType' => 'PRODUCT_CATEGORY', 'conflict' => false],
        ]);
        self::assertTrue($ok['ok']);
        self::assertSame(2, $ok['affected']);
        self::assertSame(1, $ok['excludedConflicts']);

        $refuse = $service->validateBulkApproval('series', [
            'rule' => 'x',
            'decision' => 'APPROVED_CREATE',
        ], array_fill(0, 51, ['evidenceRule' => 'x', 'conflict' => false]));
        self::assertFalse($refuse['ok']);
    }

    public function testIdempotentApplyPlanningHasZeroCreatesWithoutApprovals(): void
    {
        $service = new PlanBatchAClassificationService();
        $phase = [
            'classification' => [
                'categories' => [
                    ['termId' => 1, 'name' => 'Odd', 'nameDecoded' => 'Odd', 'conceptClass' => 'internal_category_candidate'],
                ],
                'series' => [
                    ['legacyStyleCode' => 'ZZ', 'family' => 'CABLE'],
                ],
            ],
        ];
        $azure = [
            'families' => [['code' => 'CABLE', 'name' => 'Cables']],
            'categories' => [],
            'series' => [],
        ];
        $a = $service->dryRun($phase, $azure, [])->toArray();
        $b = $service->dryRun($phase, $azure, [])->toArray();
        self::assertSame($a['payload']['dryRunTargetPlan']['categories']['create'], $b['payload']['dryRunTargetPlan']['categories']['create']);
        self::assertSame(0, $a['payload']['dryRunTargetPlan']['categories']['create']);
        self::assertSame(0, $a['payload']['dryRunTargetPlan']['series']['create']);
    }
}
