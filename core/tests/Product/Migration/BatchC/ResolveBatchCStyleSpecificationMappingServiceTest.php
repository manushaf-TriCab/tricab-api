<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchC;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchCStyleSpecificationMappingService;
use TriCab\Core\Product\Migration\Specification\SpecFieldMappingDecisionState;

final class ResolveBatchCStyleSpecificationMappingServiceTest extends TestCase
{
    public function testSameD1MapsDifferentlyByStyle(): void
    {
        $svc = new ResolveBatchCStyleSpecificationMappingService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'dvRows' => [
                [
                    'style' => 'J01',
                    'sourceField' => 'd1',
                    'legacyLabel' => 'Pins<br>&nbsp;',
                    'example' => '3',
                    'proposedDefinition' => 'Pins',
                    'status' => 'SUGGESTED',
                    'labelRows' => 1,
                ],
                [
                    'style' => 'D01',
                    'sourceField' => 'd1',
                    'legacyLabel' => 'Thread OD<br><i>mm</i>',
                    'example' => '16.00',
                    'proposedDefinition' => 'Thread OD mm',
                    'status' => 'SUGGESTED',
                    'labelRows' => 1,
                ],
            ],
            'classificationReadyProducts' => [],
            'operatorDecisions' => $svc->defaultOperatorDecisions(),
        ]);
        $items = $result['mappings']['items'];
        self::assertCount(2, $items);
        self::assertSame('PIN-COUNT', $items[0]['targetDefinitionCode']);
        self::assertSame('THREAD-OD', $items[1]['targetDefinitionCode']);
        self::assertSame(SpecFieldMappingDecisionState::APPROVED_MAP, $items[0]['decision']);
        self::assertSame(SpecFieldMappingDecisionState::APPROVED_MAP, $items[1]['decision']);
    }

    public function testGlobalStyleRejected(): void
    {
        $svc = new ResolveBatchCStyleSpecificationMappingService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'dvRows' => [[
                'style' => '*',
                'sourceField' => 'd1',
                'legacyLabel' => 'Pins',
                'example' => '12',
                'proposedDefinition' => null,
                'status' => 'REVIEW',
                'globalFieldAssumptionForbidden' => true,
                'labelRows' => 1,
            ]],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame(SpecFieldMappingDecisionState::REJECT, $result['mappings']['items'][0]['decision']);
    }

    public function testLengthRemainsReviewWithoutDefinition(): void
    {
        $svc = new ResolveBatchCStyleSpecificationMappingService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'dvRows' => [[
                'style' => 'A01',
                'sourceField' => 'v1',
                'legacyLabel' => 'Length<br><i>mm</i>',
                'example' => '100',
                'proposedDefinition' => 'Length mm',
                'status' => 'SUGGESTED',
                'labelRows' => 1,
            ]],
            'classificationReadyProducts' => [],
        ]);
        self::assertSame(SpecFieldMappingDecisionState::REVIEW, $result['mappings']['items'][0]['decision']);
        self::assertNull($result['mappings']['items'][0]['targetDefinitionCode']);
    }

    public function testExistingConflictingMappingProtected(): void
    {
        $svc = new ResolveBatchCStyleSpecificationMappingService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'dvRows' => [[
                'style' => 'J01',
                'sourceField' => 'd1',
                'legacyLabel' => 'Pins',
                'example' => '2',
                'proposedDefinition' => 'Pins',
                'status' => 'SUGGESTED',
                'labelRows' => 1,
            ]],
            'existingFieldMappings' => [[
                'sourceSystem' => 'tc',
                'legacyStyleCode' => 'J01',
                'sourceField' => 'd1',
                'targetDefinitionCode' => 'VOLTAGE',
            ]],
            'operatorDecisions' => $svc->defaultOperatorDecisions(),
            'classificationReadyProducts' => [],
        ]);
        self::assertSame(SpecFieldMappingDecisionState::CONFLICT, $result['mappings']['items'][0]['decision']);
        self::assertSame(0, $result['dryRunTargetPlan']['mappingsToUpdate']);
    }

    public function testDryRunZeroWritesAndApplyGuard(): void
    {
        $svc = new ResolveBatchCStyleSpecificationMappingService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'dvRows' => [],
            'classificationReadyProducts' => [],
        ]);
        $plan = $result['dryRunTargetPlan'];
        self::assertFalse($plan['writesWouldExecute']);
        self::assertSame('BATCH_C_APPLY_NOT_APPROVED', $plan['batchCApplyGuard']);
        self::assertSame(0, $plan['productSpecificationValuesToWrite']);
        self::assertSame(0, $plan['productMutations']);
        self::assertSame(0, $plan['idempotencyProjection']['secondRunMappingsInserted']);
    }

    public function testNormalizeLabelInsertsSpacesForHtmlTags(): void
    {
        $svc = new ResolveBatchCStyleSpecificationMappingService();
        self::assertSame('thread od mm', $svc->normalizeLabel('Thread OD<br><i>mm</i>'));
        self::assertSame('voltage v', $svc->normalizeLabel('Voltage<br><i>V<i/>'));
    }

    public function testVacuousProductNotCountedAsFieldMappingReady(): void
    {
        $svc = new ResolveBatchCStyleSpecificationMappingService();
        $result = $svc->resolve([
            'azure' => $this->azure(),
            'dvRows' => [],
            'classificationReadyProducts' => [
                ['style_code' => 'BV', 'variantCount' => 10, 'family' => 'CABLE'],
                ['style_code' => 'XX', 'variantCount' => 5, 'family' => 'CABLE'],
            ],
        ]);
        self::assertSame(0, $result['productReadiness']['CURRENT_SPEC_FIELD_MAPPING_READY']);
        self::assertSame(0, $result['productReadiness']['PROJECTED_SPEC_FIELD_MAPPING_READY_AFTER_APPLY']);
        self::assertSame(2, $result['productReadiness']['FIELD_MAPPING_NOT_APPLICABLE']);
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
                ['code' => 'PIN-COUNT', 'name' => 'Pins', 'publicId' => 'p1', 'dataTypeCode' => 'integer', 'unit' => null, 'groupCode' => 'construction'],
                ['code' => 'THREAD-OD', 'name' => 'Thread OD', 'publicId' => 'p2', 'dataTypeCode' => 'decimal', 'unit' => 'mm', 'groupCode' => 'mechanical'],
                ['code' => 'VOLTAGE', 'name' => 'Voltage', 'publicId' => 'p3', 'dataTypeCode' => 'text', 'unit' => 'V', 'groupCode' => 'electrical'],
                ['code' => 'CURRENT', 'name' => 'Current Rating', 'publicId' => 'p4', 'dataTypeCode' => 'decimal', 'unit' => 'amp', 'groupCode' => 'electrical'],
                ['code' => 'CORES', 'name' => 'Number of cores', 'publicId' => 'p5', 'dataTypeCode' => 'integer', 'unit' => null, 'groupCode' => 'construction'],
                ['code' => 'NOM-CABLE-OD', 'name' => 'Nom. Cable', 'publicId' => 'p6', 'dataTypeCode' => 'decimal', 'unit' => 'OD mm', 'groupCode' => 'mechanical'],
                ['code' => 'CORE-COLOURS', 'name' => 'Core Colours', 'publicId' => 'p7', 'dataTypeCode' => 'long-text', 'unit' => null, 'groupCode' => 'construction'],
            ],
            'units' => [],
            'optionCount' => 416,
            'productCount' => 118,
            'variantCount' => 4178,
        ];
    }
}
