<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CableSpec;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecFieldMappingService;

final class ResolveCableSpecFieldMappingServiceTest extends TestCase
{
    public function testIdentifiesExactly54CableProducts(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertSame(54, $result['cablePopulation']['expected']);
        self::assertSame(54, $result['cablePopulation']['discovered']);
        self::assertFalse($result['cablePopulation']['discrepancy']);
        self::assertSame('READY', $result['cablePopulation']['status']);
        self::assertSame('READY_FOR_OPERATOR_REVIEW', $result['packageStatus']);
        self::assertSame('12.7-batch-d1-cable-spec-field-mapping', $result['phase']);
    }

    public function testOdMapsToNomCableOdNotOuterDiameter(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $od = $this->decisionFor($result, 'od');

        self::assertSame(ResolveCableSpecFieldMappingService::PROPOSED_MAP_EXISTING, $od['decision']);
        self::assertSame('NOM-CABLE-OD', $od['targetDefinitionCode']);
        self::assertStringContainsString('OUTER-DIAMETER', (string) ($od['note'] ?? ''));
        self::assertStringContainsString('distinct', strtolower((string) ($od['note'] ?? '')));

        foreach ($result['proposedFieldMappings']['items'] as $row) {
            if (($row['sourceField'] ?? '') === 'od') {
                self::assertSame('NOM-CABLE-OD', $row['targetDefinitionCode']);
                self::assertSame('OUTER-DIAMETER', $row['neverMapTo']);
            }
        }
    }

    public function testCoreMapExistingTargets(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertSame('CORES', $this->decisionFor($result, 'nc')['targetDefinitionCode']);
        self::assertSame(
            ResolveCableSpecFieldMappingService::PROPOSED_MAP_EXISTING,
            $this->decisionFor($result, 'nc')['decision'],
        );
        self::assertSame('CSA', $this->decisionFor($result, 'xa')['targetDefinitionCode']);
        self::assertSame('APPROX-WEIGHT', $this->decisionFor($result, 'wght')['targetDefinitionCode']);
        self::assertSame('MIN-BEND-RADIUS', $this->decisionFor($result, 'br')['targetDefinitionCode']);
    }

    public function testNeverProposesIndLszhFire(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        foreach ($result['proposedDefinitions']['items'] as $def) {
            self::assertNotSame('IND-LSZH-FIRE', strtoupper((string) ($def['code'] ?? '')));
        }
        foreach ($result['proposedFieldMappings']['items'] as $row) {
            self::assertNotSame('IND-LSZH-FIRE', strtoupper((string) ($row['targetDefinitionCode'] ?? '')));
        }
        foreach ($result['conceptDecisions']['items'] as $item) {
            self::assertNotSame('IND-LSZH-FIRE', strtoupper((string) ($item['targetDefinitionCode'] ?? '')));
        }
    }

    public function testHeightUnusedIsNotASpecification(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $height = $this->decisionFor($result, 'height');

        self::assertSame(ResolveCableSpecFieldMappingService::NOT_A_SPECIFICATION, $height['decision']);
        self::assertStringContainsString('unused', strtolower((string) ($height['reason'] ?? '')));
    }

    public function testCcIsReview(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $cc = $this->decisionFor($result, 'cc');

        self::assertSame(ResolveCableSpecFieldMappingService::REVIEW, $cc['decision']);
        self::assertSame('UNKNOWN_SEMANTICS', $cc['reason']);
    }

    public function testReadinessAllReviewWhenCcPopulated(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertSame(0, $result['readiness']['products']['READY']);
        self::assertSame(54, $result['readiness']['products']['REVIEW']);
        self::assertSame(0, $result['readiness']['products']['BLOCKED']);
        self::assertSame(0, $result['readiness']['PROJECTED']['CABLE_SPEC_FIELD_MAPPING_READY']);
        self::assertSame(27, $result['readiness']['PROJECTED']['SPEC_FIELD_MAPPING_READY']);
        self::assertSame(54, $result['readiness']['PROJECTED']['FULL_PRODUCT_MIGRATION_READY']);
        self::assertSame(27, $result['readiness']['CURRENT']['ACCESSORY_DV_FIELD_MAPPING_READY']);
        self::assertTrue($result['semanticGuards']['odMapsToNomCableOd']);
        self::assertTrue($result['semanticGuards']['odNeverOuterDiameter']);
        self::assertTrue($result['semanticGuards']['noIndLszhFire']);
    }

    public function testZeroWritesInSafetyBuckets(): void
    {
        $result = $this->service()->resolve($this->baseInputs([
            'forbiddenWriteAttempts' => 0,
        ]));

        self::assertSame(0, $result['legacySafety']['wordpressWrites']);
        self::assertSame(0, $result['legacySafety']['tcWrites']);
        self::assertSame(0, $result['legacySafety']['forbiddenWriteAttempts']);
        foreach ($result['targetSafety'] as $value) {
            self::assertSame(0, $value);
        }
        self::assertFalse($result['dryRunTargetPlan']['writesWouldExecute']);
        self::assertSame(0, $result['dryRunTargetPlan']['mappingsToInsert']);
        self::assertSame('NOT_APPROVED', $result['cableSpecApply']);
        self::assertSame('DRY_RUN_ONLY', $result['mode']);
        self::assertSame('STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL', $result['nextAction']);
    }

    public function testBatchC2IntegrityEchoed(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $integrity = $result['batchC2Integrity'];

        self::assertSame(55, $integrity['definitions']);
        self::assertSame(483, $integrity['styleMappings']);
        self::assertSame(506, $integrity['totalMappings']);
        self::assertSame(0, $integrity['mappingsModified']);
        self::assertSame(0, $integrity['definitionsModified']);
        self::assertTrue($integrity['outerDiameterPreserved']);
        self::assertTrue($integrity['nomCableOdDistinct']);
        self::assertFalse($integrity['driftDetected']);
    }

    public function testDiscrepancyWhenCableCountNot54(): void
    {
        $products = array_slice($this->cableProducts(54), 0, 50);
        $result = $this->service()->resolve($this->baseInputs([
            'classificationReadyProducts' => $products,
        ]));

        self::assertSame(50, $result['cablePopulation']['discovered']);
        self::assertTrue($result['cablePopulation']['discrepancy']);
        self::assertSame('BLOCKED_POPULATION_MISMATCH', $result['cablePopulation']['status']);
        self::assertSame('NOT_READY_FOR_OPERATOR_REVIEW', $result['packageStatus']);
    }

    private function service(): ResolveCableSpecFieldMappingService
    {
        return new ResolveCableSpecFieldMappingService();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseInputs(array $overrides = []): array
    {
        $products = $overrides['classificationReadyProducts'] ?? $this->cableProducts(54);
        unset($overrides['classificationReadyProducts']);

        return array_merge([
            'azure' => [
                'server' => 'tricab-mel',
                'database' => 'tc',
                'liveValidation' => 'AVAILABLE',
                'definitionCount' => 55,
                'unitCount' => 11,
                'optionCount' => 416,
                'styleFieldMappingCount' => 483,
                'fieldMappingCount' => 506,
                'definitions' => [
                    ['code' => 'NOM-CABLE-OD'],
                    ['code' => 'OUTER-DIAMETER'],
                    ['code' => 'CORES'],
                    ['code' => 'CSA'],
                    ['code' => 'APPROX-WEIGHT'],
                    ['code' => 'MIN-BEND-RADIUS'],
                ],
            ],
            'classificationReadyProducts' => array_merge(
                $products,
                [
                    [
                        'style_code' => 'GX',
                        'family' => 'ACCESSORIES',
                        'productTitle' => 'Gland',
                    ],
                ],
            ),
            'cableSpecInventory' => $this->minimalInventory($products),
            'operatorDecisions' => [],
            'forbiddenWriteAttempts' => 0,
        ], $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cableProducts(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $style = sprintf('C%02d', $i);
            $out[] = [
                'style_code' => $style,
                'family' => 'CABLE',
                'productTitle' => 'Cable ' . $style,
                'variantCount' => 10 + $i,
                'postType' => 'cable',
                'fullProductMigrationReady' => true,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>
     */
    private function minimalInventory(array $products): array
    {
        $perStyle = [];
        foreach ($products as $p) {
            if (strtoupper((string) ($p['family'] ?? '')) !== 'CABLE') {
                continue;
            }
            $style = (string) ($p['style_code'] ?? '');
            $perStyle[] = ['cable_style' => $style, 'c' => 5];
        }

        $styleCount = count($perStyle);
        $rowCount = $styleCount * 5;

        return [
            'cableProductCount' => $styleCount,
            'stylesWithCableSpecs' => $styleCount,
            'rowCount' => $rowCount,
            'columns' => [
                'my_row_id', 'cable_style', 'nc', 'cc', 'xa', 'ne', 'xae', 'br',
                'cdia', 'ins', 'od_ins', 'ode_ins', 'od_bed', 'od_arm', 'od_brd', 'od',
                'height', 'width', 'wght', 'walu', 'wbnz',
                'A15c', 'A25c', 'A30c', 'A40c', 'A45c', 'Af',
                'r', 'x50', 'x60', 'cap', 'tension', 'mt', 'ct', 'core_con',
            ],
            'perStyleRows' => $perStyle,
            'stats' => [
                'nc' => $this->stat($rowCount, $rowCount, 8, $styleCount, ['1', '2', '3']),
                'cc' => $this->stat($rowCount, $rowCount, 4, $styleCount, ['c', 'p', 't', 'a']),
                'xa' => $this->stat($rowCount, $rowCount, 12, $styleCount, ['1.5', '2.5']),
                'ne' => $this->stat($rowCount, 20, 3, $styleCount, ['1']),
                'xae' => $this->stat($rowCount, $rowCount, 6, $styleCount, ['1.5']),
                'br' => $this->stat($rowCount, $rowCount, 10, $styleCount, ['48']),
                'cdia' => $this->stat(100, 100, 20, 10, ['1.2']),
                'ins' => $this->stat(10, 10, 5, 2, ['2.000']),
                'od_ins' => $this->stat(50, 50, 15, 8, ['8.0']),
                'ode_ins' => $this->stat(5, 5, 3, 1, ['3.4']),
                'od_bed' => $this->stat(40, 40, 12, 5, ['20.2']),
                'od_arm' => $this->stat(8, 8, 4, 2, ['15.8']),
                'od_brd' => $this->stat(40, 40, 12, 5, ['16.0']),
                'od' => $this->stat($rowCount, $rowCount, 30, $styleCount, ['12.5', '18.0']),
                'height' => $this->stat(0, 0, 0, 0, []),
                'width' => $this->stat(0, 0, 0, 0, []),
                'wght' => $this->stat($rowCount, $rowCount, 25, $styleCount, ['125']),
                'walu' => $this->stat(5, 5, 3, 1, ['10']),
                'wbnz' => $this->stat(5, 5, 3, 1, ['8']),
                'A15c' => $this->stat(0, 0, 0, 0, []),
                'A25c' => $this->stat(0, 0, 0, 0, []),
                'A30c' => $this->stat(80, 80, 10, 12, ['20']),
                'A40c' => $this->stat(80, 80, 10, 12, ['18']),
                'A45c' => $this->stat(80, 80, 10, 12, ['16']),
                'Af' => $this->stat(5, 5, 5, 1, ['1.0']),
                'r' => $this->stat(5, 5, 5, 1, ['0.2']),
                'x50' => $this->stat(5, 5, 5, 1, ['0.1']),
                'x60' => $this->stat(5, 5, 5, 1, ['0.12']),
                'cap' => $this->stat(0, 0, 0, 0, []),
                'tension' => $this->stat(5, 5, 5, 1, ['5.1']),
                'mt' => $this->stat($rowCount, $rowCount - 2, 2, $styleCount, ['1301', '1302']),
                'ct' => $this->stat($rowCount, $rowCount, 2, $styleCount, ['p', 'a']),
                'core_con' => $this->stat(0, 0, 0, 0, []),
            ],
        ];
    }

    /**
     * @param list<string> $samples
     * @return array<string, mixed>
     */
    private function stat(int $populated, int $populatedNonZero, int $distinct, int $styles, array $samples): array
    {
        return [
            'populated' => $populated,
            'populatedNonZero' => $populatedNonZero,
            'distinct' => $distinct,
            'styles' => $styles,
            'samples' => $samples,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function decisionFor(array $result, string $field): array
    {
        foreach ($result['conceptDecisions']['items'] as $item) {
            if (($item['field'] ?? '') === $field) {
                return $item;
            }
        }
        self::fail('Missing decision for field ' . $field);
    }
}
