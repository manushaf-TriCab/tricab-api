<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CableSpec;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD3CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\RecordCableSpecBatchD2OperatorDecisionsService;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD3FinalDryRunService;

final class BatchD3CableSpecFinalDryRunTest extends TestCase
{
    public function testD2DecisionsLoadedExactly(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertTrue($result['d2DecisionSource']['loadedFromArtifacts']);
        self::assertFalse($result['d2DecisionSource']['reInferred']);
        self::assertSame(7, $result['d2DecisionSource']['approvedMapExisting']);
        self::assertSame(9, $result['d2DecisionSource']['approvedCreate']);
        self::assertSame(9, $result['d2DecisionSource']['approvedNotSpecification']);
        self::assertSame(10, $result['d2DecisionSource']['review']);
        self::assertCount(35, $result['operatorDecisions']['items']);
    }

    public function testSevenExistingMappingsValidated(): void
    {
        $validation = $this->service()->resolve($this->baseInputs())['approvedExistingMappingsValidation'];
        self::assertCount(7, $validation);
        foreach ([
            'nc' => 'CORES',
            'xa' => 'CSA',
            'od' => 'NOM-CABLE-OD',
            'cdia' => 'NOM-COND-OD',
            'od_ins' => 'NOM-INS-OD',
            'br' => 'MIN-BEND-RADIUS',
            'wght' => 'APPROX-WEIGHT',
        ] as $field => $code) {
            $row = $this->validationRow($validation, $field);
            self::assertSame($code, $row['targetDefinitionCode']);
            self::assertContains($row['validationStatus'], ['VALID', 'IDENTICAL']);
        }
    }

    public function testNinePendingDefinitionsValidated(): void
    {
        $validation = $this->service()->resolve($this->baseInputs())['approvedDefinitionsValidation'];
        self::assertCount(9, $validation);
        foreach (
            [
                'ne' => 'EARTH-CORES',
                'xae' => 'EARTH-CSA',
                'ode_ins' => 'EARTH-INS-OD',
                'od_bed' => 'BEDDING-OD',
                'od_arm' => 'ARMOUR-OD',
                'od_brd' => 'BRAID-OD',
                'A30c' => 'CURRENT-30C',
                'A40c' => 'CURRENT-40C',
                'A45c' => 'CURRENT-45C',
            ] as $field => $code
        ) {
            $row = $this->definitionRow($validation, $field);
            self::assertSame($code, $row['code']);
            self::assertSame('valid', $row['validationStatus']);
        }
    }

    public function testEarthCsaDecimal(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $xae = $this->definitionRow($result['approvedDefinitionsValidation'], 'xae');
        self::assertSame('decimal', $xae['dataType']);
        self::assertTrue($result['semanticGuards']['earthCsaDecimal']);
        $compat = $this->compatRow($result['valueCompatibility'], 'xae');
        self::assertTrue($compat['typeCompatible']);
    }

    public function testAmbientCurrentDefinitionsStaySeparate(): void
    {
        $validation = $this->service()->resolve($this->baseInputs())['approvedDefinitionsValidation'];
        foreach (['A30c' => 'CURRENT-30C', 'A40c' => 'CURRENT-40C', 'A45c' => 'CURRENT-45C'] as $field => $code) {
            self::assertSame($code, $this->definitionRow($validation, $field)['code']);
        }
    }

    public function testGenericCurrentNotUsedForAmbientFields(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertTrue($result['semanticGuards']['ambientNotMappedToGenericCurrent']);
        foreach ($result['approvedFieldMappings']['items'] as $row) {
            if (in_array((string) ($row['legacyField'] ?? ''), ['A30c', 'A40c', 'A45c'], true)) {
                self::assertStringStartsWith('CURRENT-', (string) ($row['targetDefinitionCode'] ?? ''));
            }
        }
    }

    public function testOdMapsToNomCableOd(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertTrue($result['semanticGuards']['odMapsToNomCableOd']);
        $od = $this->validationRow($result['approvedExistingMappingsValidation'], 'od');
        self::assertSame('NOM-CABLE-OD', $od['targetDefinitionCode']);
    }

    public function testNeverOuterDiameter(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertTrue($result['semanticGuards']['odNeverOuterDiameter']);
        foreach ($result['approvedFieldMappings']['items'] as $row) {
            self::assertNotSame('OUTER-DIAMETER', strtoupper((string) ($row['targetDefinitionCode'] ?? '')));
        }
    }

    public function testReviewFieldsExcluded(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertSame(0, $result['reviewExclusions']['reviewConceptMappingsGenerated']);
        foreach ($result['approvedFieldMappings']['items'] as $row) {
            self::assertNotContains((string) ($row['legacyField'] ?? ''), ['cc', 'ct', 'ins', 'walu', 'wbnz', 'Af', 'r', 'x50', 'x60', 'tension']);
        }
    }

    public function testNotSpecFieldsExcluded(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertSame(0, $result['reviewExclusions']['notSpecMappingsGenerated']);
        foreach ($result['approvedFieldMappings']['items'] as $row) {
            self::assertNotContains((string) ($row['legacyField'] ?? ''), ['mt', 'cable_style', 'my_row_id']);
        }
    }

    public function testMtExcluded(): void
    {
        $items = $this->service()->resolve($this->baseInputs())['operatorDecisions']['items'];
        $mt = null;
        foreach ($items as $item) {
            if (($item['field'] ?? '') === 'mt') {
                $mt = $item;
            }
        }
        self::assertNotNull($mt);
        self::assertSame(RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_NOT_A_SPECIFICATION, $mt['decision']);
    }

    public function testNoIndLszhFire(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertTrue($result['semanticGuards']['noIndLszhFire']);
        foreach ($result['approvedDefinitionsValidation'] as $row) {
            self::assertNotSame('IND-LSZH-FIRE', strtoupper((string) ($row['code'] ?? '')));
        }
    }

    public function testZeroTargetWrites(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertSame(0, $result['targetSafety']['totalWrites']);
        self::assertSame(0, $result['applyProjection']['actualWrites']);
        self::assertSame(0, $result['applyProjection']['unitsToCreate']);
        self::assertSame(0, $result['applyProjection']['optionsToCreate']);
    }

    public function testZeroLegacyWrites(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertSame(0, $result['legacySafety']['wordpressWrites']);
        self::assertSame(0, $result['legacySafety']['tcWrites']);
    }

    public function testNoMappingUpdates(): void
    {
        $proj = $this->service()->resolve($this->baseInputs())['styleFieldMappingProjection'];
        self::assertSame(0, $proj['toUpdate']);
    }

    public function testCollisionDetection(): void
    {
        $inputs = $this->baseInputs([
            'existingMappings' => [[
                'sourceSystem' => 'tc',
                'sourceTable' => 'cable_specs',
                'sourceField' => 'nc',
                'legacyStyleCode' => 'B1',
                'targetDefinitionCode' => 'CORES',
            ], [
                'sourceSystem' => 'tc',
                'sourceTable' => 'cable_specs',
                'sourceField' => 'nc',
                'legacyStyleCode' => 'C01',
                'targetDefinitionCode' => 'CSA',
            ]],
        ]);
        $result = $this->service()->resolve($inputs);
        self::assertSame(1, $result['styleFieldMappingProjection']['conflicts']);
        self::assertSame(ResolveCableSpecBatchD3FinalDryRunService::PACKAGE_BLOCKED, $result['packageStatus']);
    }

    public function testValueCompatibilityPrecheck(): void
    {
        $compat = $this->service()->resolve($this->baseInputs())['valueCompatibility'];
        self::assertCount(16, $compat);
        foreach ($compat as $row) {
            self::assertTrue($row['passed'], (string) ($row['field'] ?? ''));
        }
    }

    public function testReadinessProjection(): void
    {
        $readiness = $this->service()->resolve($this->baseInputs())['readinessProjection'];
        self::assertSame(0, $readiness['current']['Cable READY']);
        self::assertSame(54, $readiness['current']['Cable REVIEW']);
        self::assertSame(0, $readiness['projectedAfterFutureApply']['Cable READY']);
        self::assertSame(54, $readiness['projectedAfterFutureApply']['Cable REVIEW']);
    }

    public function testIdempotencyProjection(): void
    {
        $idemp = $this->service()->resolve($this->baseInputs())['idempotencyProjection'];
        self::assertSame(0, $idemp['secondRun']['definitionsToCreate']);
        self::assertSame(0, $idemp['secondRun']['mappingsToInsert']);
        self::assertSame(0, $idemp['secondRun']['mappingsToUpdate']);
        self::assertSame(9, $idemp['secondRun']['definitionsIdenticalExisting']);
        self::assertSame(
            $this->service()->resolve($this->baseInputs())['styleFieldMappingProjection']['eligible'],
            $idemp['secondRun']['mappingsIdenticalExisting'],
        );
    }

    public function testDeterministicD3Artifacts(): void
    {
        $service = $this->service();
        $inputs = $this->baseInputs();
        $a = $service->resolve($inputs);
        $b = $service->resolve($inputs);
        unset($a['generatedAtUtc'], $b['generatedAtUtc']);
        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame('12.7-batch-d3-cable-spec-final-dry-run', $a['phase']);
        self::assertSame([
            'cable-spec-d3-approved-definitions.json',
            'cable-spec-d3-approved-field-mappings.json',
            'cable-spec-d3-review-exclusions.json',
            'cable-spec-d3-apply-projection.json',
            'cable-spec-d3-idempotency-projection.json',
            'cable-spec-d3-readiness.json',
            'cable-spec-d3-final-package.json',
        ], $a['artifacts']);
    }

    public function testApplyFlagsRejectedViaCliGuard(): void
    {
        self::assertSame(
            CableSpecBatchD3CliGuard::APPLY_NOT_APPROVED,
            CableSpecBatchD3CliGuard::refuseApply(['bin.php', '--apply']),
        );
        self::assertSame(
            CableSpecBatchD3CliGuard::APPLY_NOT_APPROVED,
            CableSpecBatchD3CliGuard::refuseApply(['bin.php', '--execute']),
        );
        self::assertSame(
            CableSpecBatchD3CliGuard::APPLY_NOT_APPROVED,
            CableSpecBatchD3CliGuard::refuseApply(['bin.php', '--write']),
        );
        self::assertNull(CableSpecBatchD3CliGuard::refuseApply(['bin.php', '--offline']));
    }

    private function service(): ResolveCableSpecBatchD3FinalDryRunService
    {
        return new ResolveCableSpecBatchD3FinalDryRunService();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseInputs(array $overrides = []): array
    {
        $d2 = (new RecordCableSpecBatchD2OperatorDecisionsService())->record([
            'd2Package' => ['operatorCandidates' => ['items' => []]],
            'inventory' => ['items' => $this->inventoryItems()],
            'classificationReadyProducts' => $this->cableProducts(54),
            'azureDefinitions' => $this->azureDefinitions55(),
            'styleFieldPresence' => $this->approvedPresence(),
        ]);

        return array_merge([
            'd2Package' => $d2,
            'inventory' => ['items' => $this->inventoryItems()],
            'classificationReadyProducts' => $this->cableProducts(54),
            'azure' => [
                'server' => 'tricab-mel',
                'database' => 'tc',
                'liveValidation' => 'AVAILABLE',
                'definitions' => $this->azureDefinitions55(),
                'definitionCount' => 55,
                'unitCount' => 11,
                'optionCount' => 416,
                'styleFieldMappingCount' => 483,
                'fieldMappingCount' => 506,
            ],
            'existingMappings' => [],
            'styleFieldPresence' => $this->approvedPresence(),
            'offlineFallbackPresence' => true,
            'forbiddenWriteAttempts' => 0,
        ], $overrides);
    }

    /**
     * @return array<string, list<string>>
     */
    private function approvedPresence(): array
    {
        $products = $this->cableProducts(54);
        $all = [];
        foreach ($products as $p) {
            $all[] = strtoupper((string) $p['style_code']);
        }
        $embedded = CableSpecBatchD2InvestigationCollector::embeddedEvidence()['styleFieldPresence'];
        $counts = [
            'nc' => 54, 'xa' => 54, 'od' => 54, 'cdia' => 41, 'od_ins' => 18, 'br' => 54, 'wght' => 54,
            'ne' => 54, 'xae' => 54, 'ode_ins' => 3, 'od_bed' => 21, 'od_arm' => 3, 'od_brd' => 21,
            'A30c' => 22, 'A40c' => 13, 'A45c' => 18,
        ];
        $out = $embedded;
        foreach ($counts as $field => $n) {
            if (!isset($out[$field])) {
                $out[$field] = $n >= count($all) ? $all : array_slice($all, 0, $n);
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function validationRow(array $rows, string $field): array
    {
        foreach ($rows as $row) {
            if (($row['field'] ?? '') === $field) {
                return $row;
            }
        }
        self::fail('Missing validation row for ' . $field);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function definitionRow(array $rows, string $field): array
    {
        foreach ($rows as $row) {
            if (($row['field'] ?? '') === $field) {
                return $row;
            }
        }
        self::fail('Missing definition row for ' . $field);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function compatRow(array $rows, string $field): array
    {
        foreach ($rows as $row) {
            if (($row['field'] ?? '') === $field) {
                return $row;
            }
        }
        self::fail('Missing compatibility row for ' . $field);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cableProducts(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $style = $i === 0 ? 'B1' : sprintf('C%02d', $i);
            $out[] = ['style_code' => $style, 'family' => 'CABLE', 'productTitle' => 'Cable ' . $style];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventoryItems(): array
    {
        $fields = [
            'A15c' => [0, 0], 'A25c' => [0, 0], 'A30c' => [80, 22], 'A40c' => [60, 13], 'A45c' => [70, 18],
            'Af' => [5, 1], 'br' => [270, 54], 'cable_style' => [270, 54], 'cap' => [0, 0], 'cc' => [270, 54],
            'cdia' => [270, 41], 'core_con' => [0, 0], 'ct' => [270, 54], 'height' => [0, 0], 'ins' => [10, 5],
            'mt' => [270, 54], 'my_row_id' => [270, 54], 'nc' => [270, 54], 'ne' => [270, 54], 'od' => [270, 54],
            'od_arm' => [100, 3], 'od_bed' => [100, 21], 'od_brd' => [100, 21], 'od_ins' => [200, 18],
            'ode_ins' => [200, 3], 'r' => [5, 1], 'tension' => [5, 1], 'walu' => [50, 14], 'wbnz' => [50, 14],
            'wght' => [270, 54], 'width' => [0, 0], 'x50' => [5, 1], 'x60' => [5, 1], 'xa' => [270, 54],
            'xae' => [270, 54],
        ];
        $items = [];
        foreach ($fields as $field => [$populated, $styles]) {
            $samples = $populated > 0
                ? (in_array($field, ['nc', 'ne'], true) ? ['1', '2', '10'] : ['10', '12.5'])
                : [];
            $items[] = [
                'field' => $field,
                'populated' => $populated,
                'styles' => $styles,
                'samples' => $samples,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function azureDefinitions55(): array
    {
        $codes = [
            'VOLTAGE', 'CURRENT', 'CORES', 'CSA', 'SHEATH', 'STANDARD', 'IP-RATING', 'TEMP-RANGE',
            'DEFAULT-COLOUR', 'CODE_BRAID_SCREEN', 'CODE_CONDUCTOR', 'CODE_CORE_COLOUR', 'CODE_CORE_COUNT',
            'CODE_CORE_TYPE', 'CODE_CROSS_SECTION', 'CODE_JACKET_COLOUR', 'CODE_JACKET_TYPE', 'CODE_STYLE',
            'CORE-COLOURS', 'CONDUCTORS', 'FLAME-BARRIER', 'CORE-INSULATION', 'SEPARATOR', 'BRAID',
            'SEPARATOR-OUTER', 'JACKET', 'JACKET-OPTION-A', 'JACKET-OPTION-C', 'JACKET-OPTION-M',
            'APPROVALS', 'APPROVAL-NOTE', 'SIZE', 'MIN-BEND-RADIUS', 'NOM-COND-OD', 'NOM-INS-OD',
            'NOM-CABLE-OD', 'APPROX-WEIGHT', 'OPERATING-TEMP', 'HCL-EMISSION', 'OXYGEN-INDEX',
            'PIN-COUNT', 'THREAD-OD', 'OVERALL-LENGTH', 'WIDTH', 'HEIGHT', 'INNER-DIAMETER',
            'CABLE-LENGTH', 'BARREL-LENGTH', 'STUD-OD', 'OUTER-DIAMETER', 'THREAD-LENGTH', 'BARREL-OD',
            'THICKNESS', 'DEPTH', 'PIN-DIAMETER',
        ];
        $out = [];
        foreach ($codes as $code) {
            $out[] = [
                'code' => $code,
                'publicId' => '00000000-0000-0000-0000-' . substr(md5($code), 0, 12),
                'dataTypeCode' => 'decimal',
                'unit' => $code === 'CURRENT' ? 'A' : null,
                'groupCode' => $code === 'CURRENT' ? 'electrical' : 'mechanical',
            ];
        }

        return $out;
    }
}
