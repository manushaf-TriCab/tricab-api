<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchA;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\PlanBatchAClassificationService;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchA1CategoryService;
use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\ClassificationNameNormalizer;
use TriCab\Core\Product\Migration\Classification\LegacyTermSemanticClassifier;
use TriCab\Core\Product\Migration\Classification\LegacyTermSemanticType;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;

final class ResolveBatchA1CategoryServiceTest extends TestCase
{
    public function testHtmlEntityNormalizationAndDuplicateRelation(): void
    {
        $n = new ClassificationNameNormalizer();
        self::assertSame('power and control', $n->normalize('Power &amp; Control'));
        self::assertSame('power and control', $n->normalize('Power & Control'));
    }

    public function testSemanticTypesForNonCategories(): void
    {
        $c = new LegacyTermSemanticClassifier();
        self::assertSame(
            LegacyTermSemanticType::APPROVAL,
            $c->classify(['name' => 'Electrical Contracting Certificates'])['semanticType'],
        );
        self::assertSame(
            LegacyTermSemanticType::PRODUCT_ATTRIBUTE,
            $c->classify(['name' => 'Copper'])['semanticType'],
        );
        self::assertSame(
            LegacyTermSemanticType::PRODUCT_ATTRIBUTE,
            $c->classify(['name' => 'Fire Resistant'])['semanticType'],
        );
        self::assertSame(
            LegacyTermSemanticType::STANDARD,
            $c->classify(['name' => 'AS/NZS 5000'])['semanticType'],
        );
    }

    public function testCategoryResolutionProposesAccessoriesWithoutAutoApprove(): void
    {
        $service = new ResolveBatchA1CategoryService();
        $result = $service->resolve(
            [
                [
                    'termId' => 10,
                    'rawName' => 'Glands',
                    'decodedName' => 'Glands',
                    'normalizedName' => 'glands',
                    'productUsageCount' => 5,
                    'decision' => CategoryDecisionState::REVIEW,
                ],
                [
                    'termId' => 11,
                    'rawName' => 'Copper',
                    'decodedName' => 'Copper',
                    'normalizedName' => 'copper',
                    'productUsageCount' => 8,
                    'decision' => CategoryDecisionState::REVIEW,
                ],
                [
                    'termId' => 12,
                    'rawName' => 'Mystery',
                    'decodedName' => 'Mystery',
                    'normalizedName' => 'mystery',
                    'productUsageCount' => 1,
                    'decision' => CategoryDecisionState::REVIEW,
                ],
            ],
            [
                [
                    'termId' => 99,
                    'decodedName' => 'VFD / VSD',
                    'normalizedName' => 'vfd vsd',
                    'decision' => CategoryDecisionState::APPROVED_MAP_EXISTING,
                    'targetCategoryCode' => 'VFD',
                ],
            ],
            [
                [
                    'normalizedName' => 'power and control',
                    'rawValues' => ['Power &amp; Control', 'Power & Control'],
                    'termIds' => [1, 2],
                ],
            ],
            [
                [
                    'styleCode' => 'B3C, B3M',
                    'family' => 'CABLE',
                    'productTitle' => 'Composite',
                    'categoryCode' => null,
                ],
                [
                    'styleCode' => '10',
                    'family' => 'CABLE',
                    'productTitle' => 'Numeric',
                    'categoryCode' => 'VFD',
                ],
                [
                    'styleCode' => 'BV',
                    'family' => 'CABLE',
                    'productTitle' => 'RI',
                    'categoryCode' => null,
                    'variantCount' => 4,
                ],
            ],
            [
                'targetLiveValidation' => 'UNAVAILABLE',
                'families' => [
                    ['code' => 'CABLE', 'name' => 'Cables'],
                    ['code' => 'ACCESSORIES', 'name' => 'Accessories'],
                    ['code' => 'PLUG', 'name' => 'Plugs'],
                ],
                'categories' => [
                    ['code' => 'VFD', 'name' => 'VFD / VSD', 'familyCode' => 'CABLE'],
                    ['code' => 'FLEX-POWER', 'name' => 'Flexible Power', 'familyCode' => 'CABLE'],
                ],
                'series' => [
                    ['code' => 'TC-FLEX', 'name' => 'TriCab Flex'],
                ],
                'productCount' => 118,
                'variantCount' => 4178,
                'catalogueNodeCount' => 97,
            ],
            ['server' => 'tricab-mel', 'database' => 'tc', 'environmentLabel' => 'NON-PRODUCTION'],
        );

        self::assertSame('UNAVAILABLE', $result['targetLiveValidation']);
        self::assertSame('BLOCKED_TARGET_UNAVAILABLE', $result['approvalBoundary']['finalApplyReadiness']);
        self::assertFalse($result['approvalBoundary']['vfdMappingApplied']);
        self::assertSame(0, $result['legacyWrites']);
        self::assertSame(0, $result['productWrites']);

        $counts = $result['categories']['counts'];
        self::assertSame(1, $counts[CategoryDecisionState::APPROVED_MAP_EXISTING]);
        self::assertSame(0, $counts[CategoryDecisionState::APPROVED_CREATE]);
        self::assertGreaterThanOrEqual(1, $counts[CategoryDecisionState::PROPOSED_CREATE]);
        self::assertGreaterThanOrEqual(1, $counts[CategoryDecisionState::NOT_A_PRODUCT_CATEGORY]);
        self::assertGreaterThanOrEqual(1, $counts[CategoryDecisionState::REVIEW]);

        $glands = null;
        foreach ($result['categories']['resolvedReview'] as $row) {
            if ((int) $row['sourceTermId'] === 10) {
                $glands = $row;
            }
        }
        self::assertNotNull($glands);
        self::assertSame(CategoryDecisionState::PROPOSED_CREATE, $glands['decision']);
        self::assertSame('GLANDS', $glands['proposedCategoryCode']);
        self::assertSame('ACCESSORIES', $glands['proposedFamily']);

        foreach ($result['approvedSets']['categories'] as $approved) {
            self::assertNotSame(CategoryDecisionState::PROPOSED_CREATE, $approved['decision'] ?? null);
        }

        $dup = $result['duplicates'][0];
        self::assertSame('HTML_ENTITY_VARIANT', $dup['relationship']);
        self::assertFalse($dup['autoMerge']);

        $seriesCounts = $result['series']['counts'];
        self::assertGreaterThanOrEqual(1, $seriesCounts[SeriesDecisionState::INVALID]);
        self::assertGreaterThanOrEqual(1, $seriesCounts[SeriesDecisionState::REVIEW]);

        $composite = null;
        foreach ($result['series']['items'] as $row) {
            if (($row['styleCode'] ?? '') === 'B3C, B3M') {
                $composite = $row;
            }
        }
        self::assertNotNull($composite);
        self::assertSame(SeriesDecisionState::INVALID, $composite['decision']);
        self::assertSame('COMPOSITE_STYLE_CODE', $composite['reason']);
    }

    public function testSeriesBlockedWithoutApprovedCategoryAndCodeCollision(): void
    {
        $service = new ResolveBatchA1CategoryService();
        $result = $service->resolve(
            [
                [
                    'termId' => 20,
                    'decodedName' => 'Glands',
                    'normalizedName' => 'glands',
                    'productUsageCount' => 5,
                ],
            ],
            [],
            [],
            [
                [
                    'styleCode' => 'GX',
                    'family' => 'ACCESSORIES',
                    'categoryCode' => 'NOT-YET-APPROVED',
                    'productTitle' => 'Gland series',
                ],
            ],
            [
                'targetLiveValidation' => 'AVAILABLE',
                'families' => [
                    ['code' => 'ACCESSORIES', 'name' => 'Accessories'],
                    ['code' => 'CABLE', 'name' => 'Cables'],
                ],
                'categories' => [
                    ['code' => 'GLANDS', 'name' => 'Existing Collision', 'familyCode' => 'ACCESSORIES'],
                ],
                'series' => [],
                'productCount' => 118,
                'variantCount' => 4178,
                'catalogueNodeCount' => 97,
            ],
            ['server' => 'tricab-mel', 'database' => 'tc'],
        );

        $glands = $result['categories']['resolvedReview'][0];
        self::assertSame(CategoryDecisionState::REVIEW, $glands['decision']);
        self::assertSame('category_code_collision_no_silent_suffix', $glands['reason']);

        $series = $result['series']['items'][0];
        self::assertSame(SeriesDecisionState::REVIEW, $series['decision']);
        self::assertSame('parent_category_not_operator_approved', $series['reason']);
    }

    public function testOperatorApproveCreateRequiresApprovedCategoryForSeries(): void
    {
        $service = new ResolveBatchA1CategoryService();
        $result = $service->resolve(
            [],
            [
                [
                    'termId' => 1,
                    'decision' => CategoryDecisionState::APPROVED_CREATE,
                    'proposedCategoryCode' => 'TERMINALS',
                    'decodedName' => 'Terminals',
                ],
            ],
            [],
            [
                [
                    'styleCode' => 'T1',
                    'family' => 'ACCESSORIES',
                    'categoryCode' => 'TERMINALS',
                    'productTitle' => 'Terminal series',
                ],
            ],
            [
                'targetLiveValidation' => 'AVAILABLE',
                'families' => [['code' => 'ACCESSORIES', 'name' => 'Accessories']],
                'categories' => [],
                'series' => [],
            ],
            ['server' => 'x', 'database' => 'y'],
            [
                'series:T1' => [
                    'decision' => SeriesDecisionState::APPROVED_CREATE,
                    'reason' => 'operator_force',
                ],
            ],
        );

        self::assertSame(SeriesDecisionState::APPROVED_CREATE, $result['series']['items'][0]['decision']);
        self::assertCount(1, $result['approvedSets']['series']);
    }

    public function testBulkValidateStillRefusesMismatchedEvidence(): void
    {
        $planner = new PlanBatchAClassificationService();
        $result = $planner->validateBulkApproval(
            'category',
            ['rule' => 'HTML_ENTITY_VARIANT', 'decision' => CategoryDecisionState::NOT_A_PRODUCT_CATEGORY],
            [
                ['semanticType' => 'HTML_ENTITY_VARIANT', 'reason' => 'HTML_ENTITY_VARIANT', 'conflict' => false],
                ['semanticType' => 'OTHER', 'reason' => 'different', 'conflict' => false],
            ],
        );
        self::assertTrue($result['ok']);
        self::assertSame(1, $result['affected']);
        self::assertSame(1, $result['excludedConflicts']);
    }

    public function testOfflineCannotBecomeApplyReady(): void
    {
        $service = new ResolveBatchA1CategoryService();
        $result = $service->resolve([], [], [], [], [
            'targetLiveValidation' => 'UNAVAILABLE',
            'families' => [],
            'categories' => [],
            'series' => [],
        ], ['server' => 'tricab-mel', 'database' => 'tc']);
        self::assertSame('BLOCKED_TARGET_UNAVAILABLE', $result['approvalBoundary']['finalApplyReadiness']);
        self::assertSame('NOT RUN', $result['approvalBoundary']['applyStatus']);
    }
}
