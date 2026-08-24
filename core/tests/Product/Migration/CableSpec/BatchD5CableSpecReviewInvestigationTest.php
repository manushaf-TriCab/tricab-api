<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CableSpec;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ApplyCableSpecBatchD4TargetApplyService;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD5CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD5InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\InvestigateCableSpecBatchD5ReviewService;

final class BatchD5CableSpecReviewInvestigationTest extends TestCase
{
    public function testApplyWriteFlagsRejected(): void
    {
        self::assertSame(
            CableSpecBatchD5CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD5CliGuard::refuseApply(['bin.php', '--apply']),
        );
        self::assertSame(
            CableSpecBatchD5CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD5CliGuard::refuseApply(['bin.php', '--write']),
        );
        self::assertNull(CableSpecBatchD5CliGuard::refuseApply(['bin.php', '--offline']));
    }

    public function testD4IntegrityPreserved(): void
    {
        $result = $this->service()->investigate($this->baseInputs());
        $d4 = $result['d4Integrity'];

        self::assertSame('D4_INTEGRITY_OK', $d4['status']);
        self::assertSame(64, $d4['definitions']);
        self::assertSame(1044, $d4['totalFieldMappings']);
        self::assertSame(538, $d4['cableFieldMappings']);
        self::assertFalse($d4['driftDetected']);
        self::assertCount(9, $d4['d4DefinitionCodesPresent']);
    }

    public function testD4IntegrityDriftBlocksInvestigation(): void
    {
        $inputs = $this->baseInputs([
            'azure' => [
                'definitionCount' => 55,
                'fieldMappingCount' => 506,
                'cableFieldMappingCount' => 0,
                'definitions' => [],
            ],
        ]);
        $result = $this->service()->investigate($inputs);

        self::assertSame(InvestigateCableSpecBatchD5ReviewService::D5_BLOCKED_D4_INTEGRITY, $result['packageStatus']);
    }

    public function testZeroTargetAndLegacyWrites(): void
    {
        $result = $this->service()->investigate($this->baseInputs());

        self::assertSame(0, $result['legacySafety']['wordpressWrites']);
        self::assertSame(0, $result['legacySafety']['tcWrites']);
        self::assertSame(0, $result['targetSafety']['totalWrites']);
        self::assertSame(0, $result['targetSafety']['definitionWrites']);
        self::assertSame(0, $result['targetSafety']['fieldMappingWrites']);
    }

    public function testExactTenReviewConcepts(): void
    {
        $result = $this->service()->investigate($this->baseInputs());
        $fields = array_map(
            static fn (array $p): string => (string) ($p['legacyField'] ?? ''),
            $result['proposals']['items'],
        );

        self::assertSame(InvestigateCableSpecBatchD5ReviewService::REVIEW_FIELDS, $fields);
        self::assertCount(10, $fields);
    }

    public function testCcDistributionAndReviewDecision(): void
    {
        $evidence = CableSpecBatchD5InvestigationCollector::embeddedEvidence();
        $values = array_column($evidence['ccByValue'], 'value');
        self::assertSame(['c', 'p', 't', 'a'], $values);

        $cc = $this->proposal($this->service()->investigate($this->baseInputs()), 'cc');
        self::assertSame(InvestigateCableSpecBatchD5ReviewService::REVIEW, $cc['decision']);
        self::assertSame('MEDIUM', $cc['confidence']);
        self::assertSame(54, $cc['productsBlocked']);
    }

    public function testCtDistributionAndReviewDecision(): void
    {
        $evidence = CableSpecBatchD5InvestigationCollector::embeddedEvidence();
        $values = array_column($evidence['ctByValue'], 'value');
        self::assertSame(['p', 'a'], $values);

        $ct = $this->proposal($this->service()->investigate($this->baseInputs()), 'ct');
        self::assertSame(InvestigateCableSpecBatchD5ReviewService::REVIEW, $ct['decision']);
        self::assertSame('MEDIUM', $ct['confidence']);
        self::assertSame(54, $ct['productsBlocked']);
    }

    public function testCcCorrelationsPresent(): void
    {
        $evidence = CableSpecBatchD5InvestigationCollector::embeddedEvidence();
        self::assertArrayHasKey('ccCrossNcXa', $evidence);
        self::assertArrayHasKey('ccValueA', $evidence);
        self::assertContains('VUF', $evidence['ccValueA']['styles'] ?? []);
    }

    public function testCtPhysicalCorrelationEcho(): void
    {
        $evidence = CableSpecBatchD5InvestigationCollector::embeddedEvidence();
        self::assertStringContainsString('aluminium', strtolower((string) ($evidence['ctPhysicalSamples']['klComparison'] ?? '')));
    }

    public function testWaluWbnzUnitInvestigation(): void
    {
        $result = $this->service()->investigate($this->baseInputs());
        $walu = $this->proposal($result, 'walu');
        $wbnz = $this->proposal($result, 'wbnz');

        self::assertSame(InvestigateCableSpecBatchD5ReviewService::PROPOSED_CREATE_DEFINITION, $walu['decision']);
        self::assertSame('HIGH', $walu['confidence']);
        self::assertSame(14, $walu['productsBlocked']);
        self::assertSame(InvestigateCableSpecBatchD5ReviewService::PROPOSED_CREATE_UNIT, $walu['unitDecision']);
        self::assertSame('APPROX-WEIGHT-ALU', $walu['targetDefinition']);

        self::assertSame(InvestigateCableSpecBatchD5ReviewService::PROPOSED_CREATE_DEFINITION, $wbnz['decision']);
        self::assertSame(14, $wbnz['productsBlocked']);
    }

    public function testInsDimensionalInvestigation(): void
    {
        $evidence = CableSpecBatchD5InvestigationCollector::embeddedEvidence();
        self::assertSame(2, $evidence['insDimensionalCheck']['matchesThicknessFormula']);

        $ins = $this->proposal($this->service()->investigate($this->baseInputs()), 'ins');
        self::assertSame(InvestigateCableSpecBatchD5ReviewService::REVIEW, $ins['decision']);
        self::assertSame('MEDIUM', $ins['confidence']);
        self::assertSame(5, $ins['productsBlocked']);
    }

    public function testB1FieldIsolation(): void
    {
        foreach (['Af', 'r', 'x50', 'x60', 'tension'] as $field) {
            $p = $this->proposal($this->service()->investigate($this->baseInputs()), $field);
            self::assertSame(1, $p['productsBlocked'], $field);
            self::assertSame(InvestigateCableSpecBatchD5ReviewService::REVIEW, $p['decision']);
            self::assertSame('LOW', $p['confidence']);
        }
    }

    public function testHighConfidenceRequiresDirectEvidence(): void
    {
        $result = $this->service()->investigate($this->baseInputs());
        self::assertTrue($result['semanticGuards']['highConfidenceRequiresDirectEvidence']);

        $cc = $this->proposal($result, 'cc');
        $walu = $this->proposal($result, 'walu');
        self::assertSame('MEDIUM', $cc['confidence']);
        self::assertSame('HIGH', $walu['confidence']);
        self::assertStringContainsString('mt=1302', implode(' ', $walu['evidence']));
    }

    public function testNoRawCcCtOptionCreation(): void
    {
        $result = $this->service()->investigate($this->baseInputs());
        self::assertTrue($result['semanticGuards']['noRawCcCtOptions']);

        foreach (['cc', 'ct'] as $field) {
            $p = $this->proposal($result, $field);
            self::assertNotSame('CC', $p['targetDefinition']);
            self::assertNotSame('CT', $p['targetDefinition']);
            self::assertNull($p['targetOptions']);
        }
    }

    public function testNoSpeculativeDefinitionCreationForReviewFields(): void
    {
        $result = $this->service()->investigate($this->baseInputs());
        self::assertTrue($result['semanticGuards']['noSpeculativeDefinitionCreation']);

        foreach (['cc', 'ct', 'ins', 'Af', 'r', 'x50', 'x60', 'tension'] as $field) {
            $p = $this->proposal($result, $field);
            self::assertSame(InvestigateCableSpecBatchD5ReviewService::REVIEW, $p['decision'], $field);
        }
    }

    public function testSemanticGuardsPreserved(): void
    {
        $guards = $this->service()->investigate($this->baseInputs())['semanticGuards'];
        self::assertTrue($guards['noIndLszhFire']);
        self::assertTrue($guards['odNomCableOdGuardPreserved']);
        self::assertTrue($guards['d4MappingsUnchanged']);
    }

    public function testReadinessScenarios(): void
    {
        $scenarios = $this->service()->investigate($this->baseInputs())['readinessScenarios'];

        self::assertSame(0, $scenarios['current']['READY']);
        self::assertSame(54, $scenarios['current']['REVIEW']);
        self::assertSame(0, $scenarios['ccOnly']['READY']);
        self::assertSame(0, $scenarios['ctOnly']['READY']);
        self::assertSame(35, $scenarios['ccAndCt']['READY']);
        self::assertSame(19, $scenarios['ccAndCt']['REVIEW']);
        self::assertGreaterThan(0, $scenarios['highConfidenceOnly']['REVIEW']);
    }

    public function testDeterministicInvestigatePayload(): void
    {
        $service = $this->service();
        $inputs = $this->baseInputs();
        $a = $service->investigate($inputs);
        $b = $service->investigate($inputs);
        unset($a['generatedAtUtc'], $b['generatedAtUtc']);
        self::assertSame($a, $b);
    }

    public function testCollectorSelectOnly(): void
    {
        $sqlLog = [];
        $query = static function (string $sql, array $params = []) use (&$sqlLog): array {
            $sqlLog[] = $sql;
            if (stripos($sql, 'GROUP BY TRIM(CAST(cc') !== false) {
                return [['value' => 'c', 'rows_cnt' => 10, 'styles' => 2, 'distinct_nc' => 2]];
            }
            if (stripos($sql, 'GROUP BY TRIM(CAST(ct') !== false) {
                return [['value' => 'p', 'rows_cnt' => 8, 'styles' => 2, 'avg_wght' => 1.2, 'avg_od' => 10]];
            }
            if (stripos($sql, 'rating') !== false) {
                return [];
            }

            return [];
        };

        $evidence = (new CableSpecBatchD5InvestigationCollector())->collect($query, ['AB', 'KL']);
        self::assertTrue($evidence['queriesWereSelectOnly']);
        foreach ($sqlLog as $sql) {
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|CREATE)\b/i', $sql);
        }
    }

    public function testArtifactListComplete(): void
    {
        $artifacts = $this->service()->investigate($this->baseInputs())['artifacts'];
        self::assertContains('cable-spec-d5-final-review-package.json', $artifacts);
        self::assertContains('cable-spec-d5-proposals.json', $artifacts);
        self::assertCount(9, $artifacts);
    }

    private function service(): InvestigateCableSpecBatchD5ReviewService
    {
        return new InvestigateCableSpecBatchD5ReviewService();
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function proposal(array $result, string $field): array
    {
        foreach ($result['proposals']['items'] as $item) {
            if (($item['legacyField'] ?? '') === $field) {
                return $item;
            }
        }
        self::fail('Missing proposal for ' . $field);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseInputs(array $overrides = []): array
    {
        return array_merge([
            'classificationReadyProducts' => $this->realisticCableProducts(),
            'investigationEvidence' => CableSpecBatchD5InvestigationCollector::embeddedEvidence(),
            'azure' => $this->d4AzureSnapshot(),
            'forbiddenWriteAttempts' => 0,
        ], $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function realisticCableProducts(): array
    {
        $walu = ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'];
        $insOnly = ['KN', 'NR', 'NV', 'XN'];
        $styles = array_values(array_unique(array_merge(['B1'], $walu, $insOnly)));
        $i = 0;
        while (count($styles) < 54) {
            $styles[] = sprintf('Z%02d', $i++);
        }

        $out = [];
        foreach (array_slice($styles, 0, 54) as $style) {
            $out[] = ['style_code' => $style, 'family' => 'CABLE', 'productTitle' => 'Cable ' . $style];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function d4AzureSnapshot(): array
    {
        $defs = array_map(
            static fn (string $code): array => ['code' => $code],
            ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES,
        );
        foreach (['CORES', 'CSA', 'NOM-CABLE-OD', 'CONDUCTORS', 'CODE_CONDUCTOR'] as $code) {
            $defs[] = ['code' => $code];
        }

        return [
            'definitionCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST,
            'fieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST,
            'cableFieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
            'styleFieldMappingCount' => 1021,
            'definitions' => $defs,
            'units' => [['code' => 'KG_PER_KM'], ['code' => 'MM'], ['code' => 'MM2']],
        ];
    }
}
