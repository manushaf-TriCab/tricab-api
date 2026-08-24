<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchA;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchA2ClassificationService;
use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;

final class ResolveBatchA2ClassificationServiceTest extends TestCase
{
    public function testConduitsFlexibleOverlapKeepsConduitsOnly(): void
    {
        $service = new ResolveBatchA2ClassificationService();
        $result = $service->resolve(
            [
                [
                    'sourceTermId' => 614,
                    'decodedName' => 'Conduits & Fittings',
                    'normalizedName' => 'conduits and fittings',
                    'decision' => CategoryDecisionState::PROPOSED_CREATE,
                    'proposedCategoryCode' => 'CONDUITS-FITTINGS',
                    'proposedFamily' => 'ACCESSORIES',
                    'productUsageCount' => 11,
                ],
                [
                    'sourceTermId' => 538,
                    'decodedName' => 'Flexible Conduit',
                    'normalizedName' => 'flexible conduit',
                    'decision' => CategoryDecisionState::PROPOSED_CREATE,
                    'proposedCategoryCode' => 'FLEX-CONDUIT',
                    'proposedFamily' => 'ACCESSORIES',
                    'productUsageCount' => 10,
                ],
            ],
            [],
            $this->azure(),
            ['server' => 'tricab-mel', 'database' => 'tc'],
            [],
            [
                'overlaps' => [
                    '614_538' => [
                        ['postId' => 1, 'title' => 'Conduit', 'style_code' => 'F08'],
                        ['postId' => 2, 'title' => 'Coupling', 'style_code' => 'F09'],
                    ],
                ],
                'productsByTerm' => [],
            ],
        );

        self::assertSame('KEEP_CONDUITS_FITTINGS_ONLY', $result['overlaps']['conduitsVsFlexibleConduit']['decision']);
        self::assertSame(CategoryDecisionState::PROPOSED_CREATE, $result['categories']['accessoryDecisions']['CONDUITS-FITTINGS']['decision']);
        self::assertSame(CategoryDecisionState::NOT_A_PRODUCT_CATEGORY, $result['categories']['accessoryDecisions']['FLEX-CONDUIT']['decision']);
        self::assertFalse($result['categories']['accessoryDecisions']['CONDUITS-FITTINGS']['approvedCreate']);
    }

    public function testGlandsVsConduitGlandsDisjoint(): void
    {
        $service = new ResolveBatchA2ClassificationService();
        $result = $service->resolve(
            [
                [
                    'sourceTermId' => 514,
                    'decodedName' => 'Glands',
                    'normalizedName' => 'glands',
                    'decision' => CategoryDecisionState::PROPOSED_CREATE,
                    'proposedCategoryCode' => 'GLANDS',
                    'proposedFamily' => 'ACCESSORIES',
                ],
                [
                    'sourceTermId' => 519,
                    'decodedName' => 'Conduit Glands',
                    'normalizedName' => 'conduit glands',
                    'decision' => CategoryDecisionState::PROPOSED_CREATE,
                    'proposedCategoryCode' => 'CONDUIT-GLANDS',
                    'proposedFamily' => 'ACCESSORIES',
                ],
            ],
            [],
            $this->azure(),
            ['server' => 'x', 'database' => 'y'],
            [],
            ['overlaps' => ['514_519' => []], 'productsByTerm' => []],
        );

        self::assertSame(0, $result['overlaps']['glandsVsConduitGlands']['sharedProductCount']);
        self::assertSame(CategoryDecisionState::PROPOSED_CREATE, $result['categories']['accessoryDecisions']['GLANDS']['decision']);
        self::assertSame(CategoryDecisionState::REVIEW, $result['categories']['accessoryDecisions']['CONDUIT-GLANDS']['decision']);
    }

    public function testCableExistingCategoryMapsAndVfdSourceCount(): void
    {
        $service = new ResolveBatchA2ClassificationService();
        $result = $service->resolve(
            [
                [
                    'sourceTermId' => 646,
                    'decodedName' => 'Flexible Cables',
                    'normalizedName' => 'flexible cables',
                    'decision' => CategoryDecisionState::NOT_A_PRODUCT_CATEGORY,
                ],
                [
                    'sourceTermId' => 307,
                    'decodedName' => 'Instrumentation - Collective',
                    'normalizedName' => 'instrumentation collective',
                    'decision' => CategoryDecisionState::REVIEW,
                ],
                [
                    'sourceTermId' => 319,
                    'decodedName' => 'Power and Control',
                    'normalizedName' => 'power and control',
                    'decision' => CategoryDecisionState::REVIEW,
                ],
                [
                    'sourceTermId' => 373,
                    'decodedName' => 'VFD/VSD',
                    'normalizedName' => 'vfd vsd',
                    'decision' => CategoryDecisionState::APPROVED_MAP_EXISTING,
                    'targetCategoryCode' => 'VFD',
                ],
                [
                    'sourceTermId' => 324,
                    'decodedName' => 'VFD/VSD',
                    'normalizedName' => 'vfd vsd',
                    'decision' => CategoryDecisionState::APPROVED_MAP_EXISTING,
                    'targetCategoryCode' => 'VFD',
                ],
            ],
            [
                [
                    'styleCode' => 'LS',
                    'family' => 'CABLE',
                    'productTitle' => 'Flexible LSZH Rubber',
                    'variantCount' => 10,
                ],
                [
                    'styleCode' => 'B3C, B3M',
                    'family' => 'CABLE',
                    'productTitle' => 'Composite',
                ],
                [
                    'styleCode' => '10',
                    'family' => 'CABLE',
                    'productTitle' => 'Numeric',
                ],
                [
                    'styleCode' => 'TC-FLEX',
                    'family' => 'CABLE',
                    'productTitle' => 'Flex',
                    'categoryCode' => 'FLEX-POWER',
                ],
            ],
            $this->azure(),
            ['server' => 'tricab-mel', 'database' => 'tc'],
            [],
            [
                'productsByTerm' => [
                    646 => [
                        ['postId' => 3327, 'title' => 'Flexible LSZH Rubber', 'style_code' => 'LS'],
                    ],
                ],
                'overlaps' => [],
            ],
        );

        self::assertSame(CategoryDecisionState::APPROVED_MAP_EXISTING, $result['categories']['items'][0]['decision']);
        self::assertSame('FLEX-POWER', $result['categories']['items'][0]['targetCategoryCode']);
        self::assertSame('INSTRUMENTATION', $result['categories']['items'][1]['targetCategoryCode']);
        self::assertSame('CTRL-MULTI', $result['categories']['items'][2]['targetCategoryCode']);
        self::assertSame(2, $result['categories']['vfd']['sourceMappingCount']);
        self::assertSame(1, $result['categories']['vfd']['targetCategoryCount']);
        self::assertFalse($result['categories']['vfd']['applied']);

        $byStyle = [];
        foreach ($result['series']['items'] as $row) {
            $byStyle[$row['styleCode']] = $row;
        }
        self::assertSame(SeriesDecisionState::PROPOSED_CREATE, $byStyle['LS']['decision']);
        self::assertSame('FLEX-POWER', $byStyle['LS']['category']);
        self::assertSame(SeriesDecisionState::INVALID, $byStyle['B3C, B3M']['decision']);
        self::assertSame('COMPOSITE_STYLE_CODE', $byStyle['B3C, B3M']['reason']);
        self::assertSame(SeriesDecisionState::REVIEW, $byStyle['10']['decision']);
        self::assertSame(SeriesDecisionState::APPROVED_MAP_EXISTING, $byStyle['TC-FLEX']['decision']);

        foreach ($result['approvedSets']['categories'] as $row) {
            self::assertNotSame(CategoryDecisionState::PROPOSED_CREATE, $row['decision']);
        }
        self::assertSame(0, $result['categories']['counts'][CategoryDecisionState::APPROVED_CREATE]);
    }

    public function testSeriesBlockedWithoutApprovedCategoryAndOperatorCreateUnlock(): void
    {
        $service = new ResolveBatchA2ClassificationService();
        $blocked = $service->resolve(
            [
                [
                    'sourceTermId' => 514,
                    'decodedName' => 'Glands',
                    'normalizedName' => 'glands',
                    'decision' => CategoryDecisionState::PROPOSED_CREATE,
                    'proposedCategoryCode' => 'GLANDS',
                    'proposedFamily' => 'ACCESSORIES',
                ],
            ],
            [['styleCode' => 'D04', 'family' => 'ACCESSORIES', 'categoryCode' => 'GLANDS', 'productTitle' => 'Gland']],
            $this->azure(['categories' => []]),
            ['server' => 'x', 'database' => 'y'],
        );
        self::assertSame(SeriesDecisionState::REVIEW, $blocked['series']['items'][0]['decision']);
        self::assertSame('parent_category_not_operator_approved', $blocked['series']['items'][0]['reason']);

        $unlocked = $service->resolve(
            [
                [
                    'sourceTermId' => 514,
                    'decodedName' => 'Glands',
                    'normalizedName' => 'glands',
                    'decision' => CategoryDecisionState::PROPOSED_CREATE,
                    'proposedCategoryCode' => 'GLANDS',
                    'proposedFamily' => 'ACCESSORIES',
                ],
            ],
            [['styleCode' => 'D04', 'family' => 'ACCESSORIES', 'categoryCode' => 'GLANDS', 'productTitle' => 'Gland']],
            $this->azure(['categories' => []]),
            ['server' => 'x', 'database' => 'y'],
            [
                'category:514' => [
                    'decision' => CategoryDecisionState::APPROVED_CREATE,
                    'proposedCategoryCode' => 'GLANDS',
                    'proposedFamily' => 'ACCESSORIES',
                    'proposedCategoryName' => 'Glands',
                ],
            ],
        );
        self::assertSame(SeriesDecisionState::PROPOSED_CREATE, $unlocked['series']['items'][0]['decision']);
    }

    public function testApplyRemainsNotApprovedAndOfflineBlocked(): void
    {
        $service = new ResolveBatchA2ClassificationService();
        $result = $service->resolve([], [], [
            'targetLiveValidation' => 'UNAVAILABLE',
            'families' => [],
            'categories' => [],
            'series' => [],
        ], ['server' => 'tricab-mel', 'database' => 'tc']);
        self::assertSame('BATCH_A_APPLY_NOT_APPROVED', $result['approvalBoundary']['batchAApplyGuard']);
        self::assertSame('NOT RUN', $result['approvalBoundary']['applyStatus']);
        self::assertSame('BLOCKED_TARGET_UNAVAILABLE', $result['approvalBoundary']['finalApplyReadiness']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function azure(array $overrides = []): array
    {
        return array_merge([
            'targetLiveValidation' => 'AVAILABLE',
            'families' => [
                ['code' => 'CABLE', 'name' => 'Cables'],
                ['code' => 'ACCESSORIES', 'name' => 'Accessories'],
                ['code' => 'PLUG', 'name' => 'Plugs'],
            ],
            'categories' => [
                ['code' => 'FLEX-POWER', 'name' => 'Flexible Power', 'familyCode' => 'CABLE'],
                ['code' => 'CTRL-MULTI', 'name' => 'Control Multicore', 'familyCode' => 'CABLE'],
                ['code' => 'INSTRUMENTATION', 'name' => 'Instrumentation', 'familyCode' => 'CABLE'],
                ['code' => 'VFD', 'name' => 'VFD / VSD', 'familyCode' => 'CABLE'],
            ],
            'series' => [
                ['code' => 'TC-FLEX', 'name' => 'TriCab Flex', 'categoryCode' => 'FLEX-POWER'],
            ],
            'productCount' => 118,
            'variantCount' => 4178,
            'catalogueNodeCount' => 97,
        ], $overrides);
    }
}
