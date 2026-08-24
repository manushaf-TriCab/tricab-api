<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CableSpec;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2DecisionCliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\RecordCableSpecBatchD2OperatorDecisionsService;

final class BatchD2CableSpecOperatorDecisionsTest extends TestCase
{
    public function testSevenApprovedExistingMappings(): void
    {
        $result = $this->service()->record($this->baseInputs());
        foreach (
            [
                'nc' => 'CORES',
                'xa' => 'CSA',
                'od' => 'NOM-CABLE-OD',
                'cdia' => 'NOM-COND-OD',
                'od_ins' => 'NOM-INS-OD',
                'br' => 'MIN-BEND-RADIUS',
                'wght' => 'APPROX-WEIGHT',
            ] as $field => $code
        ) {
            $d = $this->decision($result, $field);
            self::assertSame(RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_MAP_EXISTING, $d['decision'], $field);
            self::assertSame($code, $d['code']);
            self::assertSame('HIGH', $d['confidence']);
        }
        self::assertCount(7, $result['approvedExistingMappings']);
    }

    public function testNineApprovedCreateIncludingAmbientSplit(): void
    {
        $result = $this->service()->record($this->baseInputs());
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
            $d = $this->decision($result, $field);
            self::assertSame(RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_CREATE, $d['decision'], $field);
            self::assertSame($code, $d['code'], $field);
            self::assertTrue($d['createDeferred']);
        }
        self::assertCount(9, $result['approvedDefinitions']);
        self::assertSame(9, $result['decisionCounts'][RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_CREATE]);
    }

    public function testEarthCsaDecimalCorrection(): void
    {
        $xae = $this->decision($this->service()->record($this->baseInputs()), 'xae');
        self::assertSame('decimal', $xae['dataType']);
        self::assertSame('text', $xae['d1DataTypeCorrectedFrom']);
        self::assertSame('EARTH-CSA', $xae['code']);
        self::assertSame('Earth CSA', $xae['name']);
    }

    public function testMtApprovedNotASpecification(): void
    {
        $mt = $this->decision($this->service()->record($this->baseInputs()), 'mt');
        self::assertSame(
            RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_NOT_A_SPECIFICATION,
            $mt['decision'],
        );
        self::assertSame('MEASUREMENT_SYSTEM_METADATA', $mt['reason']);
    }

    public function testMtNoLongerBlocksProjectedReadiness(): void
    {
        $result = $this->service()->record($this->baseInputs());
        self::assertSame(0, $result['blockerMatrix']['criticalCodeBlockers']['mt']);
        $still = $result['readinessProjection']['projectedAfterApprovedDecisionsApplied']['stillBlockingConcepts'];
        self::assertArrayNotHasKey('mt', $still);
    }

    public function testCcRemainsReviewUnknownSemantics(): void
    {
        $cc = $this->decision($this->service()->record($this->baseInputs()), 'cc');
        self::assertSame(RecordCableSpecBatchD2OperatorDecisionsService::REVIEW, $cc['decision']);
        self::assertSame('UNKNOWN_SEMANTICS', $cc['reason']);
        self::assertFalse($this->service()->record($this->baseInputs())['ccCtFinalSearch']['operatorReconsiderationAvailable']);
    }

    public function testCtRemainsReviewUnknownSemantics(): void
    {
        $ct = $this->decision($this->service()->record($this->baseInputs()), 'ct');
        self::assertSame(RecordCableSpecBatchD2OperatorDecisionsService::REVIEW, $ct['decision']);
        self::assertSame('UNKNOWN_SEMANTICS', $ct['reason']);
        self::assertFalse($this->service()->record($this->baseInputs())['ccCtFinalSearch']['definitiveEvidenceFound']);
    }

    public function testAmbientCurrentSplitApprovedCreate(): void
    {
        $result = $this->service()->record($this->baseInputs());
        $ambient = $result['ambientCurrentValidation'];
        self::assertTrue($ambient['passed']);
        self::assertSame('HIGH', $ambient['confidence']);
        foreach (['A30c', 'A40c', 'A45c'] as $field) {
            self::assertTrue($ambient['fields'][$field]['passed']);
            self::assertTrue($ambient['fields'][$field]['readyForD3Package']);
            self::assertNotSame('CURRENT', $this->decision($result, $field)['code']);
        }
    }

    public function testGenericCurrentNotUsedForAmbientFields(): void
    {
        $result = $this->service()->record($this->baseInputs());
        self::assertTrue($result['semanticGuards']['ambientNotMappedToGenericCurrent']);
        foreach (['A30c', 'A40c', 'A45c'] as $field) {
            $d = $this->decision($result, $field);
            self::assertSame('CURRENT', $d['neverMapTo']);
            self::assertStringStartsWith('CURRENT-', (string) $d['code']);
        }
    }

    public function testA15cA25cRemainExcluded(): void
    {
        $result = $this->service()->record($this->baseInputs());
        foreach (['A15c', 'A25c'] as $field) {
            self::assertSame(
                RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_NOT_A_SPECIFICATION,
                $this->decision($result, $field)['decision'],
            );
        }
    }

    public function testInsRemainsReviewTypeAmbiguity(): void
    {
        $ins = $this->decision($this->service()->record($this->baseInputs()), 'ins');
        self::assertSame(RecordCableSpecBatchD2OperatorDecisionsService::REVIEW, $ins['decision']);
        self::assertSame('TYPE_AMBIGUITY', $ins['reason']);
    }

    public function testB1FieldsRemainReview(): void
    {
        $result = $this->service()->record($this->baseInputs());
        foreach (['Af', 'r', 'x50', 'x60', 'tension'] as $field) {
            $d = $this->decision($result, $field);
            self::assertSame(RecordCableSpecBatchD2OperatorDecisionsService::REVIEW, $d['decision'], $field);
            self::assertStringContainsString('SINGLE_STYLE', (string) $d['reason']);
        }
    }

    public function testWaluWbnzRemainReviewUnitAmbiguity(): void
    {
        $result = $this->service()->record($this->baseInputs());
        foreach (['walu', 'wbnz'] as $field) {
            $d = $this->decision($result, $field);
            self::assertSame(RecordCableSpecBatchD2OperatorDecisionsService::REVIEW, $d['decision'], $field);
            self::assertSame('UNIT_AMBIGUITY', $d['reason'], $field);
        }
        self::assertSame('UNIT_AMBIGUITY', $result['waluWbnzUnitValidation']['reason']);
        self::assertTrue($result['waluWbnzUnitValidation']['doNotInventUnit']);
    }

    public function testNoIndLszhFire(): void
    {
        $result = $this->service()->record($this->baseInputs());
        self::assertTrue($result['semanticGuards']['noIndLszhFire']);
        foreach ($result['operatorDecisions']['items'] as $item) {
            self::assertNotSame('IND-LSZH-FIRE', strtoupper((string) ($item['code'] ?? '')));
        }
    }

    public function testOdMapsToNomCableOdNeverOuterDiameter(): void
    {
        $result = $this->service()->record($this->baseInputs());
        self::assertTrue($result['semanticGuards']['odMapsToNomCableOd']);
        self::assertTrue($result['semanticGuards']['odNeverOuterDiameter']);
        $od = $this->decision($result, 'od');
        self::assertSame('NOM-CABLE-OD', $od['code']);
        self::assertSame('OUTER-DIAMETER', $od['neverMapTo']);
    }

    public function testBlockerRecalculationCcCtBlockAllMtDoesNot(): void
    {
        $result = $this->service()->record($this->baseInputs());
        $blockers = $result['blockerMatrix']['criticalCodeBlockers'];
        self::assertSame(54, $blockers['cc']);
        self::assertSame(54, $blockers['ct']);
        self::assertSame(0, $blockers['mt']);

        $byConcept = [];
        foreach ($result['blockerMatrix']['conceptSummary'] as $row) {
            $byConcept[(string) $row['concept']] = (int) $row['productsBlocked'];
        }
        self::assertSame(54, $byConcept['cc']);
        self::assertSame(54, $byConcept['ct']);
        self::assertArrayNotHasKey('mt', $byConcept);
        self::assertArrayNotHasKey('A30c', $byConcept);
        self::assertArrayNotHasKey('A40c', $byConcept);
        self::assertArrayNotHasKey('A45c', $byConcept);
        self::assertSame(1, $byConcept['Af']);
        self::assertSame(14, $byConcept['walu']);
        self::assertSame(14, $byConcept['wbnz']);
        self::assertSame(1, $byConcept['ins']);
    }

    public function testProjectedReadyZeroWhileCcCtReview(): void
    {
        $result = $this->service()->record($this->baseInputs());
        $proj = $result['readinessProjection'];
        self::assertSame(0, $proj['currentTarget']['CABLE_SPEC_FIELD_MAPPING_READY']);
        self::assertSame(27, $proj['currentTarget']['SPEC_FIELD_MAPPING_READY']);
        self::assertSame(0, $proj['projectedAfterApprovedDecisionsApplied']['READY']);
        self::assertSame(54, $proj['projectedAfterApprovedDecisionsApplied']['REVIEW']);
    }

    public function testDeterministicDecisionArtifactsAndCounts(): void
    {
        $service = $this->service();
        $inputs = $this->baseInputs();
        $a = $service->record($inputs);
        $b = $service->record($inputs);
        unset($a['generatedAtUtc'], $b['generatedAtUtc']);
        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame('12.7-batch-d2-cable-spec-operator-decisions', $a['phase']);
        self::assertSame('OPERATOR_DECISION_RECORDING_ONLY', $a['mode']);
        self::assertSame(7, $a['decisionCounts'][RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_MAP_EXISTING]);
        self::assertSame(9, $a['decisionCounts'][RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_CREATE]);
        self::assertSame(9, $a['decisionCounts'][RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_NOT_A_SPECIFICATION]);
        self::assertSame(10, $a['decisionCounts'][RecordCableSpecBatchD2OperatorDecisionsService::REVIEW]);
        self::assertArrayNotHasKey(RecordCableSpecBatchD2OperatorDecisionsService::REJECT, $a['decisionCounts']);
        self::assertSame([
            'cable-spec-d2-operator-decisions.json',
            'cable-spec-d2-approved-existing-mappings.json',
            'cable-spec-d2-approved-definitions.json',
            'cable-spec-d2-approved-not-specifications.json',
            'cable-spec-d2-unresolved-review.json',
            'cable-spec-d2-post-decision-readiness.json',
            'cable-spec-d2-final-decision-package.json',
        ], $a['artifacts']);
    }

    public function testZeroTargetAndLegacyWrites(): void
    {
        $result = $this->service()->record($this->baseInputs());
        self::assertSame(0, $result['legacySafety']['wordpressWrites']);
        self::assertSame(0, $result['legacySafety']['tcWrites']);
        self::assertSame(0, $result['legacySafety']['forbiddenWriteAttempts']);
        foreach ($result['targetSafety'] as $value) {
            self::assertSame(0, $value);
        }
        self::assertSame(0, $result['batchC2Integrity']['definitionsModified']);
        self::assertSame(0, $result['batchC2Integrity']['mappingsModified']);
        self::assertSame(55, $result['batchC2Integrity']['definitions']);
        self::assertSame(483, $result['batchC2Integrity']['styleMappings']);
    }

    public function testDecisionApplyUnavailableViaCliGuard(): void
    {
        self::assertSame(
            CableSpecBatchD2DecisionCliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD2DecisionCliGuard::refuseApply(['bin.php', '--apply']),
        );
        self::assertSame(
            'CABLE_SPEC_D2_DECISION_APPLY_NOT_AVAILABLE',
            CableSpecBatchD2DecisionCliGuard::refuseApply(['bin.php', '--execute']),
        );
        self::assertSame(
            CableSpecBatchD2DecisionCliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD2DecisionCliGuard::refuseApply(['bin.php', '--write']),
        );
        self::assertNull(CableSpecBatchD2DecisionCliGuard::refuseApply(['bin.php', '--offline']));
    }

    private function service(): RecordCableSpecBatchD2OperatorDecisionsService
    {
        return new RecordCableSpecBatchD2OperatorDecisionsService();
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function decision(array $result, string $field): array
    {
        foreach ($result['operatorDecisions']['items'] as $item) {
            if (($item['field'] ?? '') === $field) {
                return $item;
            }
        }
        self::fail('Missing decision for field ' . $field);
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
            'd2Package' => [
                'phase' => '12.7-batch-d2-cable-spec-operator-review',
                'packageStatus' => 'READY_FOR_OPERATOR_DECISIONS',
            ],
            'inventory' => ['items' => $this->inventoryItems()],
            'classificationReadyProducts' => array_merge($products, [
                ['style_code' => 'GX', 'family' => 'ACCESSORIES', 'productTitle' => 'Gland'],
            ]),
            'azureDefinitions' => $this->azureDefinitions55(),
            'azureUnits' => [
                ['code' => 'A'],
                ['code' => 'KG_PER_KM'],
                ['code' => 'MM'],
                ['code' => 'MM2'],
            ],
            'styleFieldPresence' => CableSpecBatchD2InvestigationCollector::embeddedEvidence()['styleFieldPresence'],
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
            // First style is B1 so embedded styleFieldPresence (Af/r/x50/x60/tension) matches.
            $style = $i === 0 ? 'B1' : sprintf('C%02d', $i);
            $out[] = [
                'style_code' => $style,
                'family' => 'CABLE',
                'productTitle' => 'Cable ' . $style,
            ];
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
            'cdia' => [270, 54], 'core_con' => [0, 0], 'ct' => [270, 54], 'height' => [0, 0], 'ins' => [10, 5],
            'mt' => [270, 54], 'my_row_id' => [270, 54], 'nc' => [270, 54], 'ne' => [270, 54], 'od' => [270, 54],
            'od_arm' => [100, 20], 'od_bed' => [100, 20], 'od_brd' => [100, 20], 'od_ins' => [200, 40],
            'ode_ins' => [200, 40], 'r' => [5, 1], 'tension' => [5, 1], 'walu' => [50, 14], 'wbnz' => [50, 14],
            'wght' => [270, 54], 'width' => [0, 0], 'x50' => [5, 1], 'x60' => [5, 1], 'xa' => [270, 54],
            'xae' => [270, 54],
        ];
        $items = [];
        foreach ($fields as $field => [$populated, $styles]) {
            $samples = $populated > 0
                ? (in_array($field, ['cc', 'ct'], true) ? ['c', 'p'] : ['10', '12.5', '100'])
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
        self::assertCount(55, $codes);
        $out = [];
        foreach ($codes as $code) {
            $out[] = [
                'code' => $code,
                'name' => $code,
                'dataTypeCode' => 'decimal',
                'unit' => $code === 'CURRENT' ? 'amp' : null,
                'groupCode' => $code === 'CURRENT' ? 'electrical' : 'mechanical',
            ];
        }

        return $out;
    }
}
