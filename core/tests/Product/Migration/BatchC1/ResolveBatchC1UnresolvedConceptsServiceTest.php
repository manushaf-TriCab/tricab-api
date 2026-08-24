<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchC1;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchC1UnresolvedConceptsService;
use TriCab\Core\Product\Migration\Specification\SpecFieldMappingDecisionState;

final class ResolveBatchC1UnresolvedConceptsServiceTest extends TestCase
{
    public function testGroupsSameLabelDifferentMeaningSeparately(): void
    {
        $svc = new ResolveBatchC1UnresolvedConceptsService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => [
                'mappings' => [
                    'items' => [
                        $this->row('A01', 'd1', 'length mm', 'REVIEW', null),
                        $this->row('B01', 'd2', 'thread length mm', 'REVIEW', null),
                        $this->row('C01', 'd1', 'barrel mm', 'REVIEW', null),
                    ],
                ],
            ],
            'classificationReadyProducts' => [],
        ]);
        $concepts = array_column($result['unresolvedConcepts']['items'], 'concept');
        self::assertContains('OVERALL_LENGTH_MM', $concepts);
        self::assertContains('THREAD_LENGTH_MM', $concepts);
        self::assertContains('BARREL_LENGTH_MM', $concepts);
    }

    public function testMapExistingColourToCoreColours(): void
    {
        $svc = new ResolveBatchC1UnresolvedConceptsService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => [
                'mappings' => [
                    'items' => [
                        $this->row('X01', 'v1', 'colour', 'PROPOSED_MAP', 'CORE-COLOURS'),
                    ],
                ],
            ],
            'classificationReadyProducts' => [],
        ]);
        $colour = null;
        foreach ($result['unresolvedConcepts']['items'] as $c) {
            if (($c['concept'] ?? '') === 'CORE_COLOUR_MATRIX') {
                $colour = $c;
            }
        }
        self::assertNotNull($colour);
        self::assertSame('MAP_EXISTING', $colour['decision']);
        self::assertSame('CORE-COLOURS', $colour['existingTargetCandidate']);
    }

    public function testPreservesApproved124Rows(): void
    {
        $svc = new ResolveBatchC1UnresolvedConceptsService();
        $items = [];
        for ($i = 0; $i < 5; ++$i) {
            $items[] = $this->row('J0' . $i, 'd1', 'pins', SpecFieldMappingDecisionState::APPROVED_MAP, 'PIN-COUNT');
        }
        $items[] = $this->row('A01', 'd2', 'width mm', 'REVIEW', null);
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => $items]],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame(5, $result['startingBatchCState']['APPROVED_MAP']);
        self::assertSame(5, $result['mappingStateAfterC1Review']['APPROVED_MAP']);
    }

    public function testPhaseTypeConflictResolvesToCoreColours(): void
    {
        $svc = new ResolveBatchC1UnresolvedConceptsService();
        $row = $this->row('J50', 'v1', 'phase', 'REVIEW', 'CORES');
        $row['typeCompatible'] = false;
        $row['sampleValues'] = ['BLACK'];
        $row['targetDataType'] = 'integer';
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => [$row]]],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame(1, $result['typeConflicts']['before']);
        self::assertSame(1, $result['typeConflicts']['resolvedByC1Recommendation']);
        self::assertSame('CORE-COLOURS', $result['typeConflicts']['items'][0]['recommendedTarget']);
    }

    public function testVacuousCableIsNotApplicableNotComplete(): void
    {
        $svc = new ResolveBatchC1UnresolvedConceptsService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => []]],
            'classificationReadyProducts' => [
                ['style_code' => 'BV', 'family' => 'CABLE', 'variantCount' => 100],
            ],
        ]);
        self::assertSame(1, $result['vacuousReadinessAudit']['FIELD_MAPPING_NOT_APPLICABLE']);
        self::assertSame(0, $result['vacuousReadinessAudit']['FIELD_MAPPING_COMPLETE']);
        self::assertSame(100, $result['variantReadinessAudit']['VARIANT_DV_NOT_APPLICABLE']);
        self::assertSame(0, $result['variantReadinessAudit']['VARIANT_SPEC_MAPPING_COMPLETE']);
    }

    public function testZeroWriteDryRunGuards(): void
    {
        $svc = new ResolveBatchC1UnresolvedConceptsService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => []]],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame('BATCH_C1_APPLY_NOT_APPROVED', $result['approvalBoundary']['batchC1ApplyGuard']);
        self::assertSame(0, $result['targetWrites']['definitionsCreated']);
        self::assertSame(0, $result['targetWrites']['mappingsInserted']);
    }

    public function testLengthSplitIntoSubconcepts(): void
    {
        $svc = new ResolveBatchC1UnresolvedConceptsService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'batchCPackage' => ['mappings' => ['items' => []]],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame('SPLIT', $result['specialReviews']['LENGTH']['decision']);
        self::assertArrayHasKey('OVERALL_LENGTH_MM', $result['specialReviews']['LENGTH']['subgroups']);
        self::assertArrayHasKey('THREAD_LENGTH_MM', $result['specialReviews']['LENGTH']['subgroups']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $style, string $field, string $label, string $decision, ?string $code): array
    {
        return [
            'styleCode' => $style,
            'sourceField' => $field,
            'normalizedLabel' => $label,
            'legacyLabel' => $label,
            'decision' => $decision,
            'targetDefinitionCode' => $code,
            'sampleValues' => ['12.00'],
            'typeCompatible' => true,
            'unitCompatible' => true,
        ];
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
                ['code' => 'THREAD-OD', 'name' => 'Thread OD', 'dataTypeCode' => 'decimal', 'unit' => 'mm'],
            ],
            'definitionCount' => 42,
            'unitCount' => 11,
            'optionCount' => 416,
        ];
    }
}
