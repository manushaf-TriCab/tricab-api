<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchC2;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchC2OperatorApprovalService;
use TriCab\Core\Product\Migration\Specification\SpecFieldMappingDecisionState;

final class ResolveBatchC2OperatorApprovalServiceTest extends TestCase
{
    public function testDefinitionCountReconciliationExcludesIndFrom12(): void
    {
        $svc = new ResolveBatchC2OperatorApprovalService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => []]],
            'batchC1Package' => [
                'definitionProposals' => [
                    'proposedCreate' => [
                        ['proposedDefinition' => ['code' => 'LENGTH'], 'concept' => 'OVERALL_LENGTH_MM'],
                        ['proposedDefinition' => ['code' => 'WIDTH'], 'concept' => 'WIDTH_MM'],
                    ],
                ],
                'unresolvedConcepts' => ['items' => []],
                'specialReviews' => [
                    'ind_lszh_fire' => ['decision' => 'PROPOSE_DEFINITION', 'code' => 'IND-LSZH-FIRE'],
                ],
            ],
            'classificationReadyProducts' => [],
        ]);
        $rec = $result['definitionCountReconciliation'];
        self::assertFalse($rec['indLszhFireIncludedInPrevious12']);
        self::assertSame(2, $rec['mechanicalDimensionProposals']);
        self::assertSame(1, $rec['otherProposals']);
        self::assertSame(3, $rec['totalUniqueDefinitionCandidates']);
    }

    public function testOuterDiameterCreatesDistinctDefinitionNotNomCableOd(): void
    {
        $svc = new ResolveBatchC2OperatorApprovalService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => [
                'mappings' => [
                    'items' => [[
                        'styleCode' => 'B07',
                        'sourceField' => 'd2',
                        'normalizedLabel' => 'outerdia mm',
                        'legacyLabel' => 'Outer⌀ mm',
                        'decision' => SpecFieldMappingDecisionState::PROPOSED_MAP,
                        'targetDefinitionCode' => 'NOM-CABLE-OD',
                        'sampleValues' => ['12.00'],
                    ]],
                ],
            ],
            'batchC1Package' => [
                'definitionProposals' => ['proposedCreate' => []],
                'unresolvedConcepts' => [
                    'items' => [[
                        'concept' => 'OUTER_DIAMETER_MM',
                        'mappingRows' => 1,
                        'stylesAffected' => 1,
                        'legacyLabels' => ['outerdia mm'],
                        'sampleValues' => ['12.00'],
                        'confidence' => 'medium',
                    ]],
                ],
            ],
            'classificationReadyProducts' => [],
        ]);
        $outer = null;
        foreach ($result['conceptDecisions']['items'] as $c) {
            if (($c['concept'] ?? '') === 'OUTER_DIAMETER_MM') {
                $outer = $c;
            }
        }
        self::assertNotNull($outer);
        self::assertSame(ResolveBatchC2OperatorApprovalService::STATUS_APPROVED_CREATE, $outer['decision']);
        self::assertSame('OUTER-DIAMETER', $outer['code']);
        self::assertSame(
            ResolveBatchC2OperatorApprovalService::STATUS_APPROVED_PENDING_DEFINITION,
            $result['approvedFieldMappings'][0]['status'] ?? null,
        );
        self::assertSame('OUTER-DIAMETER', $result['approvedFieldMappings'][0]['targetDefinitionCode'] ?? null);
    }

    public function testCoresCannotAcceptColourValues(): void
    {
        $svc = new ResolveBatchC2OperatorApprovalService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => [
                'mappings' => [
                    'items' => [[
                        'styleCode' => 'J50',
                        'sourceField' => 'v1',
                        'normalizedLabel' => 'phase',
                        'decision' => SpecFieldMappingDecisionState::REVIEW,
                        'targetDefinitionCode' => 'CORES',
                        'sampleValues' => ['BLACK'],
                        'typeCompatible' => false,
                    ]],
                ],
            ],
            'batchC1Package' => [
                'definitionProposals' => ['proposedCreate' => []],
                'unresolvedConcepts' => [
                    'items' => [[
                        'concept' => 'PHASE_COLOUR_MISLABEL',
                        'mappingRows' => 1,
                        'stylesAffected' => 1,
                        'legacyLabels' => ['phase'],
                        'sampleValues' => ['BLACK'],
                        'confidence' => 'high',
                    ]],
                ],
            ],
            'classificationReadyProducts' => [],
        ]);
        $map = $result['approvedFieldMappings'][0] ?? null;
        self::assertNotNull($map);
        self::assertSame('CORE-COLOURS', $map['targetDefinitionCode']);
        self::assertNotSame('CORES', $map['targetDefinitionCode']);
    }

    public function testPreservesOriginalApprovedMaps(): void
    {
        $svc = new ResolveBatchC2OperatorApprovalService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => [
                'mappings' => [
                    'items' => [[
                        'styleCode' => 'J01',
                        'sourceField' => 'd1',
                        'normalizedLabel' => 'pins',
                        'decision' => SpecFieldMappingDecisionState::APPROVED_MAP,
                        'targetDefinitionCode' => 'PIN-COUNT',
                        'sampleValues' => ['3'],
                    ]],
                ],
            ],
            'batchC1Package' => [
                'definitionProposals' => ['proposedCreate' => []],
                'unresolvedConcepts' => ['items' => []],
            ],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame(1, $result['mappingState']['originalApprovalsStillValid']);
        self::assertSame(0, $result['mappingState']['revalidationConflicts']);
    }

    public function testZeroWritesAndApplyGuard(): void
    {
        $svc = new ResolveBatchC2OperatorApprovalService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => []]],
            'batchC1Package' => [
                'definitionProposals' => ['proposedCreate' => []],
                'unresolvedConcepts' => ['items' => []],
            ],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame('BATCH_C2_APPLY_NOT_APPROVED', $result['approvalBoundary']['batchC2ApplyGuard']);
        self::assertFalse($result['dryRunTargetPlan']['writesWouldExecute']);
        self::assertSame(0, $result['dryRunTargetPlan']['specificationValues']['productValues']);
        self::assertSame(0, $result['dryRunTargetPlan']['idempotencyProjection']['definitionsCreatedOnSecondRun']);
    }

    public function testCableDvNotApplicableIsNotCableSpecReady(): void
    {
        $svc = new ResolveBatchC2OperatorApprovalService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => []]],
            'batchC1Package' => [
                'definitionProposals' => ['proposedCreate' => []],
                'unresolvedConcepts' => ['items' => []],
            ],
            'classificationReadyProducts' => [
                ['style_code' => 'BV', 'family' => 'CABLE', 'variantCount' => 100],
            ],
        ]);
        self::assertSame(0, $result['readiness']['PROJECTED_AFTER_BATCH_C2_APPLY']['CABLE_SPEC_FIELD_MAPPING_READY']);
        self::assertTrue($result['cableSpecsFollowUpRequired']);
        self::assertSame(1, $result['readiness']['productBreakdown']['cableProducts']['dvNotApplicable']);
    }

    public function testIndLszhFireRemainsReviewNotAutoCreate(): void
    {
        $svc = new ResolveBatchC2OperatorApprovalService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => []]],
            'batchC1Package' => [
                'definitionProposals' => ['proposedCreate' => []],
                'unresolvedConcepts' => ['items' => []],
            ],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame(
            ResolveBatchC2OperatorApprovalService::STATUS_REVIEW,
            $result['specialDecisions']['ind_lszh_fire']['decision'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function azure(): array
    {
        return [
            'server' => 'tricab-mel',
            'database' => 'tc',
            'liveValidation' => 'AVAILABLE',
            'definitions' => [
                ['code' => 'CORE-COLOURS', 'name' => 'Core Colours', 'dataTypeCode' => 'long-text'],
                ['code' => 'CORES', 'name' => 'Number of cores', 'dataTypeCode' => 'integer'],
                ['code' => 'PIN-COUNT', 'name' => 'Pins', 'dataTypeCode' => 'integer'],
                ['code' => 'NOM-CABLE-OD', 'name' => 'Nom. Cable', 'dataTypeCode' => 'decimal', 'unit' => 'OD mm'],
                ['code' => 'CSA', 'name' => 'Conductor CSA', 'dataTypeCode' => 'text', 'unit' => 'mm²'],
            ],
            'definitionCount' => 42,
            'unitCount' => 11,
            'optionCount' => 416,
        ];
    }
}
