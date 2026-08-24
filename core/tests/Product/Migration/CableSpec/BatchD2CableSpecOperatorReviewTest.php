<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CableSpec;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD2OperatorReviewService;

final class BatchD2CableSpecOperatorReviewTest extends TestCase
{
    public function testSchemaInvestigationRemainsSelectOnly(): void
    {
        $sqlLog = [];
        $query = static function (string $sql, array $params = []) use (&$sqlLog): array {
            $sqlLog[] = $sql;
            if (stripos($sql, 'INFORMATION_SCHEMA') !== false) {
                return [['TABLE_NAME' => 'descript', 'COLUMN_NAME' => 'desc_id']];
            }
            if (stripos($sql, 'FROM descript') !== false) {
                return [
                    ['desc_id' => 1301, 'code' => 'M', 'title' => 'Metric System', 'desc_type' => 13],
                    ['desc_id' => 1302, 'code' => 'I', 'title' => 'Imperial System', 'desc_type' => 13],
                ];
            }
            if (stripos($sql, 'GROUP BY TRIM(CAST(cc') !== false) {
                return [
                    ['cc' => 'c', 'mt' => '1301', 'ct' => 'p', 'rows_cnt' => 10, 'styles' => 2],
                ];
            }

            return [['populated' => 0, 'styles' => 0, 'numeric_compatible' => 0, 'walu_nz' => 0, 'wbnz_nz' => 0]];
        };

        $evidence = (new CableSpecBatchD2InvestigationCollector())->collect($query, ['C01', 'C02']);

        self::assertTrue($evidence['queriesWereSelectOnly']);
        self::assertNotSame([], $sqlLog);
        foreach ($sqlLog as $sql) {
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|CREATE)\b/i', $sql);
            self::assertMatchesRegularExpression('/^\s*SELECT\b/i', trim($sql));
        }
    }

    public function testCcEvidenceRemainsReviewMedium(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $cc = $result['criticalFindings']['cc'];

        self::assertSame(ResolveCableSpecBatchD2OperatorReviewService::REVIEW, $cc['decision']);
        self::assertSame('MEDIUM', $cc['confidence']);
        self::assertSame(['c', 'p', 't', 'a'], $cc['values']);
        self::assertNotSame([], $cc['evidence']);
    }

    public function testMtLookupDiscoveryNotASpecification(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $mt = $result['criticalFindings']['mt'];

        self::assertSame(
            ResolveCableSpecBatchD2OperatorReviewService::NOT_A_SPECIFICATION_CANDIDATE,
            $mt['decision'],
        );
        self::assertSame('HIGH', $mt['confidence']);
        self::assertStringContainsString('Metric', (string) $mt['1301']);
        self::assertStringContainsString('Imperial', (string) $mt['1302']);
        self::assertSame('unset/null', $mt['0']);

        $lookup = $mt['descriptLookup'];
        self::assertIsArray($lookup);
        $ids = array_map(static fn (array $r): int => (int) ($r['desc_id'] ?? -1), $lookup);
        self::assertContains(1301, $ids);
        self::assertContains(1302, $ids);
    }

    public function testCtEvidenceRemainsReviewMedium(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $ct = $result['criticalFindings']['ct'];

        self::assertSame(ResolveCableSpecBatchD2OperatorReviewService::REVIEW, $ct['decision']);
        self::assertSame('MEDIUM', $ct['confidence']);
        self::assertArrayHasKey('p', $ct);
        self::assertArrayHasKey('a', $ct);
    }

    public function testCcMtCtCorrelationMatrixHardcodedCombinations(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $matrix = $result['correlations']['matrix'];

        self::assertCount(10, $matrix);
        self::assertSame(
            ['cc' => 'c', 'mt' => '1301', 'ct' => 'p', 'rows' => 1601, 'styles' => 27],
            $matrix[0],
        );
        self::assertStringContainsString('Do not create 3 Definitions', (string) $result['correlations']['interpretation']);
    }

    public function testInsInvestigationRemainsTypeAmbiguityReview(): void
    {
        $ins = $this->candidate($this->service()->resolve($this->baseInputs()), 'ins');

        self::assertSame(ResolveCableSpecBatchD2OperatorReviewService::REVIEW, $ins['decisionCandidate']);
        self::assertSame('MEDIUM', $ins['confidence']);
        self::assertSame('TYPE_AMBIGUITY', $ins['reason']);
    }

    public function testWaluWbnzApprovedCreateDistinctFromApproxWeight(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $walu = $this->candidate($result, 'walu');
        $wbnz = $this->candidate($result, 'wbnz');

        self::assertSame(
            ResolveCableSpecBatchD2OperatorReviewService::APPROVED_CREATE_CANDIDATE,
            $walu['decisionCandidate'],
        );
        self::assertSame(
            ResolveCableSpecBatchD2OperatorReviewService::APPROVED_CREATE_CANDIDATE,
            $wbnz['decisionCandidate'],
        );
        self::assertSame('HIGH', $walu['confidence']);
        self::assertSame('APPROX-WEIGHT-ALU', $walu['code']);
        self::assertSame('APPROX-WEIGHT-BRONZE', $wbnz['code']);
        self::assertNotSame('APPROX-WEIGHT', $walu['code']);
        self::assertStringContainsString('mt', (string) ($walu['unitNote'] ?? ''));
    }

    public function testLowFrequencyFieldsRemainReview(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        foreach (['Af', 'r', 'x50', 'x60', 'tension'] as $field) {
            $c = $this->candidate($result, $field);
            self::assertSame(ResolveCableSpecBatchD2OperatorReviewService::REVIEW, $c['decisionCandidate'], $field);
            self::assertStringContainsString('SINGLE_STYLE', (string) $c['reason']);
        }
        self::assertSame('LOW', $this->candidate($result, 'Af')['confidence']);
        self::assertSame('MEDIUM', $this->candidate($result, 'r')['confidence']);
    }

    public function testAmbientCurrentSemanticCollisionDetection(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $ambient = $result['ambientCurrentInvestigation'];

        self::assertTrue($ambient['collisionDetected']);
        self::assertSame('AMBIENT_CURRENT_SEMANTIC_COLLISION', $ambient['collisionReason']);
        self::assertFalse($ambient['singleCurrentDefinitionSufficient']);
        foreach (['A30c', 'A40c', 'A45c'] as $field) {
            self::assertSame(
                ResolveCableSpecBatchD2OperatorReviewService::REVIEW,
                $ambient['fields'][$field]['decisionCandidate'],
            );
            self::assertSame('AMBIENT_CURRENT_SEMANTIC_COLLISION', $ambient['fields'][$field]['reason']);
            self::assertSame('PROPOSED_MAP_EXISTING', $ambient['fields'][$field]['d1Decision']);
        }
        self::assertSame(
            ResolveCableSpecBatchD2OperatorReviewService::NOT_A_SPECIFICATION_CANDIDATE,
            $ambient['fields']['A15c']['decisionCandidate'],
        );
    }

    public function testEarthCsaTypeValidationRecommendsDecimal(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $earth = $result['earthCsaTypeValidation'];

        self::assertSame('decimal', $earth['typeRecommendation']);
        self::assertSame('text', $earth['d1DataType']);
        self::assertSame(4491, $earth['numericCompatible']);
        self::assertSame(
            ResolveCableSpecBatchD2OperatorReviewService::APPROVED_CREATE_CANDIDATE,
            $earth['decisionCandidate'],
        );

        $xae = $this->candidate($result, 'xae');
        self::assertSame('decimal', $xae['dataType']);
        self::assertSame('decimal', $xae['typeRecommendation']);
    }

    public function testExistingMappingsPreservedExceptAmbient(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
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
            $c = $this->candidate($result, $field);
            self::assertSame(
                ResolveCableSpecBatchD2OperatorReviewService::APPROVED_MAP_EXISTING_CANDIDATE,
                $c['decisionCandidate'],
                $field,
            );
            self::assertSame($code, $c['code']);
            self::assertSame('HIGH', $c['confidence']);
        }
    }

    public function testOdMapsToNomCableOdNeverOuterDiameter(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertTrue($result['semanticGuards']['odMapsToNomCableOd']);
        self::assertTrue($result['semanticGuards']['odNeverOuterDiameter']);
        $od = $this->candidate($result, 'od');
        self::assertSame('NOM-CABLE-OD', $od['code']);
        self::assertSame('OUTER-DIAMETER', $od['neverMapTo']);
    }

    public function testNoIndLszhFireInCandidates(): void
    {
        $result = $this->service()->resolve($this->baseInputs());

        self::assertTrue($result['semanticGuards']['noIndLszhFire']);
        foreach ($result['operatorCandidates']['items'] as $item) {
            self::assertNotSame('IND-LSZH-FIRE', strtoupper((string) ($item['code'] ?? '')));
        }
    }

    public function testBlockerMatrixCriticalCodes(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $blockers = $result['blockerMatrix']['criticalCodeBlockers'];

        self::assertSame(54, $blockers['cc']);
        self::assertSame(54, $blockers['mt']);
        self::assertSame(54, $blockers['ct']);
        self::assertCount(54, $result['blockerMatrix']['products']);
    }

    public function testReadinessProjections(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $proj = $result['readinessProjection'];

        self::assertSame(0, $proj['current']['READY']);
        self::assertSame(54, $proj['current']['REVIEW']);
        self::assertSame(0, $proj['highConfidence']['READY'], 'cc/ct REVIEW keep HIGH projection at 0');
        self::assertArrayHasKey('ifCcMtCtResolved', $proj);
        self::assertArrayHasKey('READY', $proj['ifCcMtCtResolved']);
        self::assertArrayHasKey('stillBlockingConcepts', $proj['ifCcMtCtResolved']);
        // Ambient collision fields remain REVIEW → still block without style presence for A30c/etc.
        self::assertSame(0, $proj['ifCcMtCtResolved']['READY']);
        self::assertArrayHasKey('A30c', $proj['ifCcMtCtResolved']['stillBlockingConcepts']);
    }

    public function testDeterministicD2ArtifactsAndPackageStatus(): void
    {
        $service = $this->service();
        $inputs = $this->baseInputs();
        $a = $service->resolve($inputs);
        $b = $service->resolve($inputs);

        unset($a['generatedAtUtc'], $b['generatedAtUtc']);
        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame('READY_FOR_OPERATOR_DECISIONS', $a['packageStatus']);
        self::assertSame('12.7-batch-d2-cable-spec-operator-review', $a['phase']);
        self::assertSame('READ_INVESTIGATE_REVIEW_ONLY', $a['mode']);
        self::assertSame([
            'cable-spec-d2-semantic-investigation.json',
            'cable-spec-d2-code-correlations.json',
            'cable-spec-d2-operator-candidates.json',
            'cable-spec-d2-blocker-matrix.json',
            'cable-spec-d2-readiness-projection.json',
            'cable-spec-d2-final-review-package.json',
        ], $a['artifacts']);
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
    }

    public function testD2ApplyUnavailableViaCliGuard(): void
    {
        self::assertSame(
            CableSpecBatchD2CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD2CliGuard::refuseApply(['bin.php', '--apply']),
        );
        self::assertSame(
            CableSpecBatchD2CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD2CliGuard::refuseApply(['bin.php', '--execute']),
        );
        self::assertSame(
            CableSpecBatchD2CliGuard::APPLY_NOT_AVAILABLE,
            CableSpecBatchD2CliGuard::refuseApply(['bin.php', '--write']),
        );
        self::assertNull(CableSpecBatchD2CliGuard::refuseApply(['bin.php', '--offline']));
    }

    public function testCandidateCountsAndCreatesIncludeWeights(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        $counts = $result['candidateCounts'];

        self::assertSame(7, $counts[ResolveCableSpecBatchD2OperatorReviewService::APPROVED_MAP_EXISTING_CANDIDATE]);
        self::assertSame(8, $counts[ResolveCableSpecBatchD2OperatorReviewService::APPROVED_CREATE_CANDIDATE]);
        self::assertSame(9, $counts[ResolveCableSpecBatchD2OperatorReviewService::NOT_A_SPECIFICATION_CANDIDATE]);
        self::assertSame(11, $counts[ResolveCableSpecBatchD2OperatorReviewService::REVIEW]);
        self::assertArrayNotHasKey(ResolveCableSpecBatchD2OperatorReviewService::REJECT_CANDIDATE, $counts);
        self::assertSame(55, $result['batchC2Integrity']['definitions']);
        self::assertSame(483, $result['batchC2Integrity']['styleMappings']);
        self::assertFalse($result['d1Reuse']['resolveCableSpecFieldMappingServiceReplaced']);
    }

    public function testCollectorSanitizesStylesLikeD1(): void
    {
        $collector = new CableSpecBatchD2InvestigationCollector();
        self::assertSame(['AB', 'C1'], $collector->sanitizeStyles(['ab', 'C1', 'bad;drop', '', 'x y']));
    }

    private function service(): ResolveCableSpecBatchD2OperatorReviewService
    {
        return new ResolveCableSpecBatchD2OperatorReviewService();
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function candidate(array $result, string $field): array
    {
        foreach ($result['operatorCandidates']['items'] as $item) {
            if (($item['field'] ?? '') === $field) {
                return $item;
            }
        }
        self::fail('Missing candidate for field ' . $field);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseInputs(array $overrides = []): array
    {
        $products = $overrides['classificationReadyProducts'] ?? $this->cableProducts(54);
        unset($overrides['classificationReadyProducts']);

        $d1 = $overrides['d1Package'] ?? $this->minimalD1Package($products);
        unset($overrides['d1Package']);

        return array_merge([
            'd1Package' => $d1,
            'classificationReadyProducts' => array_merge($products, [
                ['style_code' => 'GX', 'family' => 'ACCESSORIES', 'productTitle' => 'Gland'],
            ]),
            'investigationEvidence' => CableSpecBatchD2InvestigationCollector::embeddedEvidence(),
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
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>
     */
    private function minimalD1Package(array $products): array
    {
        $styleCount = count($products);
        $rowCount = $styleCount * 5;
        $items = [];
        $fields = [
            'A15c', 'A25c', 'A30c', 'A40c', 'A45c', 'Af', 'br', 'cable_style', 'cap', 'cc', 'cdia',
            'core_con', 'ct', 'height', 'ins', 'mt', 'my_row_id', 'nc', 'ne', 'od', 'od_arm', 'od_bed',
            'od_brd', 'od_ins', 'ode_ins', 'r', 'tension', 'walu', 'wbnz', 'wght', 'width', 'x50', 'x60',
            'xa', 'xae',
        ];
        foreach ($fields as $field) {
            $items[] = [
                'field' => $field,
                'populated' => in_array($field, ['height', 'width', 'A15c', 'A25c', 'cap', 'core_con'], true) ? 0 : $rowCount,
                'styles' => in_array($field, ['height', 'width', 'A15c', 'A25c', 'cap', 'core_con'], true) ? 0 : $styleCount,
                'samples' => [],
            ];
        }
        // Sparse overrides matching live-ish shape for ambient / B1 fields.
        foreach ($items as &$item) {
            if ($item['field'] === 'A30c') {
                $item['populated'] = 80;
                $item['styles'] = 12;
            }
            if (in_array($item['field'], ['Af', 'r', 'x50', 'x60', 'tension'], true)) {
                $item['populated'] = 5;
                $item['styles'] = 1;
            }
            if ($item['field'] === 'ins') {
                $item['populated'] = 10;
                $item['styles'] = 2;
            }
        }
        unset($item);

        return [
            'phase' => '12.7-batch-d1-cable-spec-field-mapping',
            'packageStatus' => 'READY_FOR_OPERATOR_REVIEW',
            'conceptDecisions' => [
                'items' => [
                    ['field' => 'nc', 'decision' => 'PROPOSED_MAP_EXISTING', 'code' => 'CORES'],
                    ['field' => 'od', 'decision' => 'PROPOSED_MAP_EXISTING', 'code' => 'NOM-CABLE-OD'],
                    ['field' => 'A30c', 'decision' => 'PROPOSED_MAP_EXISTING', 'code' => 'CURRENT'],
                    ['field' => 'xae', 'decision' => 'PROPOSED_CREATE', 'code' => 'EARTH-CSA'],
                    ['field' => 'cc', 'decision' => 'REVIEW'],
                    ['field' => 'mt', 'decision' => 'REVIEW'],
                    ['field' => 'ct', 'decision' => 'REVIEW'],
                ],
                'counts' => [
                    'PROPOSED_MAP_EXISTING' => 10,
                    'PROPOSED_CREATE' => 6,
                    'REVIEW' => 11,
                    'NOT_A_SPECIFICATION' => 8,
                ],
            ],
            'fieldInventory' => ['items' => $items],
            'batchC2Integrity' => [
                'definitions' => 55,
                'styleMappings' => 483,
                'totalMappings' => 506,
            ],
            'target' => [
                'definitions' => 55,
                'styleFieldMappings' => 483,
                'fieldMappings' => 506,
            ],
        ];
    }
}
