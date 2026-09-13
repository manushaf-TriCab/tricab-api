<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CableSpec;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ApplyCableSpecBatchD4TargetApplyService;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD51CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD51InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD51LegacySourceScanner;
use TriCab\Core\Product\Migration\Application\Service\InvestigateCableSpecBatchD51FinalResolutionService;

final class BatchD51CableSpecFinalResolutionTest extends TestCase
{
    public function testApplyWriteFlagsRejected(): void
    {
        self::assertSame(
            CableSpecBatchD51CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD51CliGuard::refuseApply(['bin.php', '--apply']),
        );
    }

    public function testZeroWrites(): void
    {
        $r = $this->service()->resolve($this->inputs());
        self::assertSame(0, $r['legacySafety']['tcWrites']);
        self::assertSame(0, $r['targetSafety']['totalWrites']);
    }

    public function testBaselineIntegrityOk(): void
    {
        $b = $this->service()->resolve($this->inputs())['baselineIntegrity'];
        self::assertSame('BASELINE_OK', $b['status']);
        self::assertSame(64, $b['definitions']);
        self::assertSame(1044, $b['totalFieldMappings']);
        self::assertSame(538, $b['cableFieldMappings']);
    }

    public function testBaselineDriftBlocks(): void
    {
        $inputs = $this->inputs(['azure' => ['definitionCount' => 55, 'fieldMappingCount' => 506, 'cableFieldMappingCount' => 0]]);
        $r = $this->service()->resolve($inputs);
        self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::D51_BLOCKED_BASELINE_DRIFT, $r['packageStatus']);
    }

    public function testAdditiveDefinitionsOutsideTheBatchDoNotCountAsDrift(): void
    {
        $inputs = $this->inputs();
        $inputs['azure']['definitionCount'] = ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST + 17;

        $baseline = $this->service()->resolve($inputs)['baselineIntegrity'];

        self::assertSame('BASELINE_OK', $baseline['status']);
        self::assertSame(17, $baseline['definitionsAddedOutsideBaseline']);
        self::assertSame([], $baseline['missingRequiredDefinitionCodes']);
    }

    public function testMissingRequiredDefinitionStillCountsAsDrift(): void
    {
        $inputs = $this->inputs();
        array_pop($inputs['azure']['definitions']);

        $report = $this->service()->resolve($inputs);

        self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::D51_BLOCKED_BASELINE_DRIFT, $report['packageStatus']);
        self::assertCount(1, $report['baselineIntegrity']['missingRequiredDefinitionCodes']);
    }

    public function testDirectVsCorrelationConfidenceRules(): void
    {
        $cc = $this->finding('cc');
        self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::STRONG_CORRELATION, $cc['evidenceClass']);
        self::assertSame('MEDIUM', $cc['confidence']);

        $walu = $this->finding('walu');
        self::assertSame('HIGH', $walu['meaningConfidence']);
        self::assertSame('MEDIUM', $walu['unitConfidence']);
    }

    public function testCcFinalInvestigation(): void
    {
        $cc = $this->finding('cc');
        self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::EXPLICITLY_UNRESOLVED, $cc['resolution']);
        self::assertTrue($cc['operatorExclusionNotRecommended']);
        self::assertCount(4, $cc['valueBreakdown']);
    }

    public function testCtFinalInvestigation(): void
    {
        $ct = $this->finding('ct');
        self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::EXPLICITLY_UNRESOLVED, $ct['resolution']);
        self::assertTrue($ct['operatorExclusionNotRecommended']);
    }

    public function testWeightUnitProofLogic(): void
    {
        $walu = $this->finding('walu');
        self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::PROVEN_CREATE_DEFINITION, $walu['resolution']);
        self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::PROVEN_CREATE_UNIT, $walu['unitResolution']);
        self::assertSame('LB_PER_1000FT', $walu['targetUnit']);
    }

    public function testInsStyleSpecificSemantics(): void
    {
        $ins = $this->finding('ins');
        self::assertTrue($ins['scopedMappingRequired']);
        self::assertArrayHasKey('NR', $ins['perStyle']);
        self::assertTrue($ins['perStyle']['NR']['derivable']);
    }

    public function testB1InvestigationUnresolved(): void
    {
        foreach (['Af', 'r', 'x50', 'x60', 'tension'] as $field) {
            $f = $this->finding($field);
            self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::EXPLICITLY_UNRESOLVED, $f['resolution']);
            self::assertTrue($f['legacyUnresolvedExcludeCandidate']);
        }
    }

    public function testCcCtExclusionRisk(): void
    {
        $guards = $this->service()->resolve($this->inputs())['semanticGuards'];
        self::assertTrue($guards['ccCtExclusionNotRecommended']);
    }

    public function testOperatorOptionsForAllTenConcepts(): void
    {
        $opts = $this->service()->resolve($this->inputs())['operatorOptions'];
        self::assertCount(10, $opts);
        foreach ($opts as $row) {
            self::assertNotEmpty($row['options']);
        }
    }

    public function testReadinessPerOperatorOption(): void
    {
        $ready = $this->service()->resolve($this->inputs())['readinessOptions'];
        self::assertSame(0, $ready['current']['READY']);
        self::assertSame(35, $ready['ccCtResolvedMedium']['READY']);
        self::assertSame(35, $ready['perOperatorOption']['ccCtBothMediumApproved']['READY']);
        self::assertSame(54, $ready['minimumSafeClosure']['READY']);
    }

    public function testClosurePathBlockedForCcCt(): void
    {
        $closure = $this->service()->resolve($this->inputs())['closureRecommendation'];
        self::assertSame(InvestigateCableSpecBatchD51FinalResolutionService::PATH_C, $closure['recommendedPath']);
    }

    public function testDeterministicResolve(): void
    {
        $svc = $this->service();
        $inputs = $this->inputs();
        $a = $svc->resolve($inputs);
        $b = $svc->resolve($inputs);
        unset($a['generatedAtUtc'], $b['generatedAtUtc']);
        self::assertSame($a, $b);
    }

    public function testCollectorSelectOnly(): void
    {
        $log = [];
        $query = static function (string $sql, array $params = []) use (&$log): array {
            $log[] = $sql;
            if (stripos($sql, 'GROUP BY TRIM(CAST(cc') !== false) {
                return [['code' => 'c', 'rows_cnt' => 1, 'styles' => 1]];
            }

            return [];
        };
        $evidence = (new CableSpecBatchD51InvestigationCollector())->collect($query, ['KL']);
        self::assertTrue($evidence['queriesWereSelectOnly']);
        foreach ($log as $sql) {
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE)\b/i', $sql);
        }
    }

    public function testArtifactList(): void
    {
        $artifacts = $this->service()->resolve($this->inputs())['artifacts'];
        self::assertContains('cable-spec-d5-1-final-package.json', $artifacts);
        self::assertCount(8, $artifacts);
    }

    private function service(): InvestigateCableSpecBatchD51FinalResolutionService
    {
        return new InvestigateCableSpecBatchD51FinalResolutionService();
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $field): array
    {
        $findings = $this->service()->resolve($this->inputs())['fieldFindings'];

        return is_array($findings[$field] ?? null) ? $findings[$field] : [];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function inputs(array $overrides = []): array
    {
        return array_merge([
            'classificationReadyProducts' => $this->products(),
            'investigationEvidence' => CableSpecBatchD51InvestigationCollector::embeddedEvidence(),
            'legacySourceScan' => CableSpecBatchD51LegacySourceScanner::embeddedScan(),
            'd5Package' => [
                'phase' => '12.7-batch-d5-cable-spec-review-investigation',
                'd4Integrity' => ['status' => 'D4_INTEGRITY_OK'],
            ],
            'azure' => [
                'definitionCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST,
                'fieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST,
                'cableFieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
                'definitions' => array_map(static fn (string $c): array => ['code' => $c], ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES),
                'units' => [['code' => 'KG_PER_KM']],
            ],
            'forbiddenWriteAttempts' => 0,
        ], $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        $walu = ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'];
        $styles = array_values(array_unique(array_merge(['B1'], $walu, ['KN', 'NR', 'NV', 'XN'])));
        $i = 0;
        while (count($styles) < 54) {
            $styles[] = sprintf('Z%02d', $i++);
        }
        $out = [];
        foreach (array_slice($styles, 0, 54) as $style) {
            $out[] = ['style_code' => $style, 'family' => 'CABLE'];
        }

        return $out;
    }
}
