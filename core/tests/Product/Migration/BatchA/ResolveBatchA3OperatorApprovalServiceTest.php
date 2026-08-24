<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchA;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchA3OperatorApprovalService;
use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;

final class ResolveBatchA3OperatorApprovalServiceTest extends TestCase
{
    public function testTechnoFamilyPlugAndOperatorCategoryApproval(): void
    {
        $service = new ResolveBatchA3OperatorApprovalService();
        $result = $service->build(
            [
                'categories' => [
                    'items' => [
                        [
                            'sourceTermId' => 584,
                            'decodedName' => 'Techno Connectors',
                            'normalizedName' => 'techno connectors',
                            'decision' => CategoryDecisionState::PROPOSED_CREATE,
                            'proposedCategoryCode' => 'TECHNO-CONNECTORS',
                            'proposedCategoryName' => 'Techno Connectors',
                            'proposedFamily' => 'ACCESSORIES',
                            'sampleProducts' => [
                                ['title' => 'TECHNO Plug', 'style_code' => 'J93'],
                                ['title' => 'TECHNO Socket', 'style_code' => 'J94'],
                                ['title' => 'TECHNO Junction', 'style_code' => 'J90'],
                                ['title' => 'TECHNO Plug', 'style_code' => 'J95'],
                                ['title' => 'TECHNO Socket', 'style_code' => 'J96'],
                            ],
                        ],
                        [
                            'sourceTermId' => 514,
                            'decodedName' => 'Glands',
                            'normalizedName' => 'glands',
                            'decision' => CategoryDecisionState::PROPOSED_CREATE,
                            'proposedCategoryCode' => 'GLANDS',
                            'proposedCategoryName' => 'Glands',
                            'proposedFamily' => 'ACCESSORIES',
                            'postCount' => 5,
                            'sampleProducts' => [
                                ['title' => 'Long Thread', 'style_code' => 'D04'],
                            ],
                        ],
                        [
                            'sourceTermId' => 646,
                            'decodedName' => 'Flexible Cables',
                            'normalizedName' => 'flexible cables',
                            'decision' => CategoryDecisionState::APPROVED_MAP_EXISTING,
                            'targetCategoryCode' => 'FLEX-POWER',
                            'proposedFamily' => 'CABLE',
                            'sampleProducts' => [
                                ['title' => 'Flexible LSZH', 'style_code' => 'LS'],
                            ],
                        ],
                    ],
                ],
                'series' => [
                    'items' => [
                        [
                            'styleCode' => 'LS',
                            'family' => 'CABLE',
                            'category' => 'FLEX-POWER',
                            'productTitle' => 'Flexible LSZH',
                            'variantCount' => 12,
                            'decision' => SeriesDecisionState::PROPOSED_CREATE,
                            'proposedCode' => 'LS',
                            'reason' => 'eligible_pending_operator_approve_create',
                        ],
                        [
                            'styleCode' => 'D04',
                            'family' => 'ACCESSORIES',
                            'category' => 'GLANDS',
                            'productTitle' => 'Long Thread',
                            'variantCount' => 3,
                            'postType' => 'accessory',
                            'decision' => SeriesDecisionState::PROPOSED_CREATE,
                            'proposedCode' => 'D04',
                            'reason' => 'eligible_pending_operator_approve_create',
                            'identityStatus' => 'ACCESSORY_UNRESOLVED',
                        ],
                        [
                            'styleCode' => 'B3C, B3M',
                            'family' => 'CABLE',
                            'productTitle' => 'Composite',
                            'decision' => SeriesDecisionState::INVALID,
                            'reason' => 'COMPOSITE_STYLE_CODE',
                        ],
                    ],
                ],
                'overlaps' => ['pairs' => []],
            ],
            [
                'technoFamily' => 'PLUG',
                'approveCreateCodes' => [
                    'TECHNO-CONNECTORS' => ['name' => 'Techno Connectors', 'family' => 'PLUG'],
                    'GLANDS' => ['name' => 'Glands', 'family' => 'ACCESSORIES'],
                ],
                'series' => ['__approveAllEligibleProposed' => true],
            ],
            $this->azure(),
            ['server' => 'tricab-mel', 'database' => 'tc'],
        );

        self::assertSame('PLUG', $result['technoFamilyDecision']['family']);
        $byTerm = [];
        foreach ($result['categories']['items'] as $row) {
            $byTerm[(int) $row['sourceTermId']] = $row;
        }
        self::assertSame(CategoryDecisionState::APPROVED_CREATE, $byTerm[584]['decision']);
        self::assertSame('PLUG', $byTerm[584]['proposedFamily']);
        self::assertSame(CategoryDecisionState::APPROVED_CREATE, $byTerm[514]['decision']);
        self::assertSame(CategoryDecisionState::APPROVED_MAP_EXISTING, $byTerm[646]['decision']);

        foreach ($result['approvedSets']['categories'] as $row) {
            self::assertContains($row['decision'], [
                CategoryDecisionState::APPROVED_MAP_EXISTING,
                CategoryDecisionState::APPROVED_CREATE,
            ]);
        }

        $byStyle = [];
        foreach ($result['series']['items'] as $row) {
            $byStyle[$row['styleCode']] = $row;
        }
        self::assertSame(SeriesDecisionState::APPROVED_CREATE, $byStyle['LS']['decision']);
        self::assertSame(SeriesDecisionState::APPROVED_CREATE, $byStyle['D04']['decision']);

        self::assertGreaterThanOrEqual(1, $result['readiness']['CLASSIFICATION_READY']);
        $readyStyles = array_column($result['classificationReadyProducts'], 'style_code');
        self::assertContains('LS', $readyStyles);
        self::assertContains('D04', $readyStyles);

        $d04 = null;
        foreach ($result['classificationReadyProducts'] as $p) {
            if ($p['style_code'] === 'D04') {
                $d04 = $p;
            }
        }
        self::assertNotNull($d04);
        self::assertFalse($d04['fullProductMigrationReady']);

        self::assertSame('BATCH_A_APPLY_NOT_APPROVED', $result['approvalBoundary']['batchAApplyGuard']);
        self::assertSame(0, $result['targetApplyPlan']['mutations']['products']);
        self::assertSame(0, $result['targetApplyPlan']['idempotency']['secondRunCategoryCreates']);
    }

    public function testBulkSeriesSafetyAndCollision(): void
    {
        $service = new ResolveBatchA3OperatorApprovalService();
        $ok = $service->validateBulkSeriesApproval(
            [
                'rule' => 'eligible_pending_operator_approve_create',
                'decision' => SeriesDecisionState::APPROVED_CREATE,
                'family' => 'CABLE',
                'category' => 'FLEX-POWER',
            ],
            [
                [
                    'styleCode' => 'LS',
                    'family' => 'CABLE',
                    'category' => 'FLEX-POWER',
                    'reason' => 'eligible_pending_operator_approve_create',
                    'decision' => SeriesDecisionState::PROPOSED_CREATE,
                ],
                [
                    'styleCode' => 'B3C, B3M',
                    'family' => 'CABLE',
                    'category' => 'FLEX-POWER',
                    'reason' => 'eligible_pending_operator_approve_create',
                ],
            ],
        );
        self::assertTrue($ok['ok']);
        self::assertSame(1, $ok['affected']);
        self::assertSame(1, $ok['excludedConflicts']);

        $refuse = $service->validateBulkSeriesApproval(
            ['rule' => 'x', 'decision' => SeriesDecisionState::APPROVED_CREATE],
            array_fill(0, 101, ['styleCode' => 'A', 'reason' => 'x', 'family' => 'CABLE', 'category' => 'FLEX-POWER']),
        );
        self::assertFalse($refuse['ok']);
    }

    public function testTargetUnavailableBlocksPackage(): void
    {
        $service = new ResolveBatchA3OperatorApprovalService();
        $result = $service->build(
            ['categories' => ['items' => []], 'series' => ['items' => []], 'overlaps' => []],
            [],
            [
                'targetLiveValidation' => 'UNAVAILABLE',
                'families' => [],
                'categories' => [],
                'series' => [],
            ],
            ['server' => 'tricab-mel', 'database' => 'tc'],
        );
        self::assertSame('BLOCKED_TARGET_UNAVAILABLE', $result['approvalBoundary']['finalApplyPackage']);
        self::assertSame('CONTINUE REVIEW', $result['approvalBoundary']['recommendation']);
    }

    /**
     * @return array<string, mixed>
     */
    private function azure(): array
    {
        return [
            'targetLiveValidation' => 'AVAILABLE',
            'families' => [
                ['code' => 'CABLE', 'name' => 'Cables'],
                ['code' => 'PLUG', 'name' => 'Plugs'],
                ['code' => 'ACCESSORIES', 'name' => 'Accessories'],
            ],
            'categories' => [
                ['code' => 'FLEX-POWER', 'name' => 'Flexible Power', 'familyCode' => 'CABLE', 'isActive' => true],
                ['code' => 'VFD', 'name' => 'VFD', 'familyCode' => 'CABLE', 'isActive' => true],
            ],
            'series' => [
                ['code' => 'TC-FLEX', 'name' => 'TriCab Flex', 'categoryCode' => 'FLEX-POWER'],
            ],
            'productCount' => 118,
            'variantCount' => 4178,
            'catalogueNodeCount' => 97,
        ];
    }
}
