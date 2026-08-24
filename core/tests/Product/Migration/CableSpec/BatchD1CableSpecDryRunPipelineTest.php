<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CableSpec;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD1CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecLegacyInventoryCollector;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecFieldMappingService;

final class BatchD1CableSpecDryRunPipelineTest extends TestCase
{
    public function testFiftyFourProductPopulationReadyForOperatorReview(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertSame(54, $result['cablePopulation']['discovered']);
        self::assertFalse($result['cablePopulation']['discrepancy']);
        self::assertSame('READY', $result['cablePopulation']['status']);
        self::assertSame('READY_FOR_OPERATOR_REVIEW', $result['packageStatus']);
        self::assertSame('12.7-batch-d1-cable-spec-field-mapping', $result['phase']);
    }

    public function testPopulationMismatchBlocked(): void
    {
        $products = array_slice($this->cableProducts(54), 0, 40);
        $result = $this->service()->resolve($this->baseInputs([
            'classificationReadyProducts' => $products,
        ]));

        self::assertSame(40, $result['cablePopulation']['discovered']);
        self::assertTrue($result['cablePopulation']['discrepancy']);
        self::assertSame('BLOCKED_POPULATION_MISMATCH', $result['cablePopulation']['status']);
        self::assertSame('NOT_READY_FOR_OPERATOR_REVIEW', $result['packageStatus']);
        self::assertArrayHasKey('fieldInventory', $result);
        self::assertArrayHasKey('specialInvestigations', $result);
    }

    public function testOdMapsToNomCableOdNeverOuterDiameter(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertTrue($result['semanticGuards']['odMapsToNomCableOd']);
        self::assertTrue($result['semanticGuards']['odNeverOuterDiameter']);

        $odMapping = null;
        foreach ($result['proposedFieldMappings']['items'] as $row) {
            if (($row['sourceField'] ?? '') === 'od') {
                $odMapping = $row;
                break;
            }
        }
        self::assertNotNull($odMapping);
        self::assertSame('NOM-CABLE-OD', $odMapping['targetDefinitionCode']);
        self::assertSame('OUTER-DIAMETER', $odMapping['neverMapTo']);
        self::assertArrayHasKey('valueCompatibility', $odMapping);
        self::assertSame(
            (int) ($this->baseInputs()['cableSpecInventory']['stats']['od']['populated'] ?? 0),
            $odMapping['valueCompatibility']['totalValues'],
        );
    }

    public function testNoIndLszhFireInProposedDefinitions(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        foreach ($result['proposedDefinitions']['items'] as $def) {
            self::assertNotSame('IND-LSZH-FIRE', strtoupper((string) ($def['code'] ?? '')));
        }
        self::assertTrue($result['semanticGuards']['noIndLszhFire']);
    }

    public function testCcMtCtSpecialInvestigationsRemainReview(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $si = $result['specialInvestigations'];

        foreach (['cc', 'mt', 'ct'] as $field) {
            self::assertArrayHasKey($field, $si);
            self::assertSame(ResolveCableSpecFieldMappingService::REVIEW, $si[$field]['decision']);
            self::assertSame('UNKNOWN_SEMANTICS', $si[$field]['reason']);
            self::assertSame('low', $si[$field]['confidence']);
            self::assertNotSame('', (string) ($si[$field]['inferredMeaning'] ?? ''));
            self::assertIsArray($si[$field]['samples']);
        }

        self::assertSame(
            ['c', 'p', 't', 'a'],
            array_values(array_intersect(['c', 'p', 't', 'a'], $si['cc']['samples'])),
        );
        self::assertContains('1301', $si['mt']['samples']);
        self::assertContains('1302', $si['mt']['samples']);
        self::assertContains('0', $si['mt']['samples']);
        self::assertContains('p', $si['ct']['samples']);
        self::assertContains('a', $si['ct']['samples']);
    }

    public function testUnknownPopulatedFieldPreventsReady(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertSame(0, $result['readiness']['products']['READY']);
        self::assertSame(54, $result['readiness']['products']['REVIEW']);
        self::assertSame(0, $result['readiness']['CURRENT']['CABLE_SPEC_FIELD_MAPPING_READY']);
        self::assertSame(27, $result['readiness']['CURRENT']['ACCESSORY_DV_FIELD_MAPPING_READY']);
    }

    public function testZeroWritesInSafetyBuckets(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertSame(0, $result['legacySafety']['wordpressWrites']);
        self::assertSame(0, $result['legacySafety']['tcWrites']);
        self::assertSame(0, $result['legacySafety']['forbiddenWriteAttempts']);
        foreach ($result['targetSafety'] as $value) {
            self::assertSame(0, $value);
        }
        self::assertFalse($result['dryRunTargetPlan']['writesWouldExecute']);
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
        self::assertFalse($integrity['driftDetected']);
    }

    public function testApplyUnavailableViaCliGuard(): void
    {
        self::assertSame(
            CableSpecBatchD1CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD1CliGuard::refuseApply(['bin.php', '--apply']),
        );
        self::assertSame(
            CableSpecBatchD1CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD1CliGuard::refuseApply(['bin.php', '--execute']),
        );
        self::assertSame(
            CableSpecBatchD1CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD1CliGuard::refuseApply(['bin.php', '--write']),
        );
        self::assertNull(CableSpecBatchD1CliGuard::refuseApply(['bin.php', '--offline']));
    }

    public function testCollectorBuildsInventoryFromRowsOffline(): void
    {
        $columns = ['cable_style', 'cc', 'mt', 'ct', 'od', 'nc'];
        $rows = [
            ['cable_style' => 'C01', 'cc' => 'c', 'mt' => '1301', 'ct' => 'p', 'od' => '12.5', 'nc' => '3'],
            ['cable_style' => 'C01', 'cc' => 'p', 'mt' => '1302', 'ct' => 'a', 'od' => '18.0', 'nc' => '4'],
            ['cable_style' => 'C02', 'cc' => 't', 'mt' => '0', 'ct' => 'p', 'od' => '10.0', 'nc' => '2'],
        ];

        $inventory = CableSpecLegacyInventoryCollector::buildFromRows($columns, $rows, ['C01', 'C02']);

        self::assertSame(2, $inventory['cableProductCount']);
        self::assertSame(3, $inventory['rowCount']);
        self::assertArrayHasKey('cc', $inventory['fieldDistributions']);
        self::assertNotSame([], $inventory['fieldDistributions']['mt']);
        self::assertSame(3, $inventory['stats']['od']['populated']);

        $result = $this->service()->resolve($this->baseInputs([
            'cableSpecInventory' => $inventory,
            'fieldDistributions' => $inventory['fieldDistributions'],
            'classificationReadyProducts' => $this->cableProducts(54),
        ]));

        self::assertSame('READY_FOR_OPERATOR_REVIEW', $result['packageStatus']);
        self::assertSame(ResolveCableSpecFieldMappingService::REVIEW, $result['specialInvestigations']['mt']['decision']);
    }

    public function testArtifactsListMatchesBatchD1(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertSame([
            'cable-spec-field-inventory.json',
            'cable-spec-concept-proposals.json',
            'cable-spec-proposed-field-mappings.json',
            'cable-spec-review.json',
            'cable-spec-readiness.json',
            'cable-spec-final-dry-run-package.json',
            'cable-spec-special-investigations.json',
        ], $result['artifacts']);
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

        $inventory = $overrides['cableSpecInventory'] ?? $this->minimalInventory($products);
        $fieldDistributions = $overrides['fieldDistributions']
            ?? (is_array($inventory['fieldDistributions'] ?? null) ? $inventory['fieldDistributions'] : $this->defaultDistributions());

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
            'cableSpecInventory' => $inventory,
            'fieldDistributions' => $fieldDistributions,
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
            'stylesWithData' => $styleCount,
            'rowCount' => $rowCount,
            'columns' => [
                'my_row_id', 'cable_style', 'nc', 'cc', 'xa', 'ne', 'xae', 'br',
                'cdia', 'ins', 'od_ins', 'ode_ins', 'od_bed', 'od_arm', 'od_brd', 'od',
                'height', 'width', 'wght', 'walu', 'wbnz',
                'A15c', 'A25c', 'A30c', 'A40c', 'A45c', 'Af',
                'r', 'x50', 'x60', 'cap', 'tension', 'mt', 'ct', 'core_con',
            ],
            'perStyleRows' => $perStyle,
            'fieldDistributions' => $this->defaultDistributions(),
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
                'mt' => $this->stat($rowCount, $rowCount - 2, 3, $styleCount, ['1301', '1302', '0']),
                'ct' => $this->stat($rowCount, $rowCount, 2, $styleCount, ['p', 'a']),
                'core_con' => $this->stat(0, 0, 0, 0, []),
            ],
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function defaultDistributions(): array
    {
        return [
            'cc' => [
                ['value' => 'c', 'count' => 2000, 'styles' => 40],
                ['value' => 'p', 'count' => 1500, 'styles' => 30],
                ['value' => 't', 'count' => 700, 'styles' => 20],
                ['value' => 'a', 'count' => 291, 'styles' => 10],
            ],
            'mt' => [
                ['value' => '1301', 'count' => 3000, 'styles' => 40],
                ['value' => '1302', 'count' => 1472, 'styles' => 30],
                ['value' => '0', 'count' => 19, 'styles' => 5],
            ],
            'ct' => [
                ['value' => 'p', 'count' => 4000, 'styles' => 50],
                ['value' => 'a', 'count' => 491, 'styles' => 20],
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
}
