<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch D.2 — Cable Spec Operator Review (read/investigate/review only).
 *
 * Reuses D.1 artifacts; never writes mappings/definitions/options/units/values.
 */
final class ResolveCableSpecBatchD2OperatorReviewService
{
    public const APPROVED_MAP_EXISTING_CANDIDATE = 'APPROVED_MAP_EXISTING_CANDIDATE';
    public const APPROVED_CREATE_CANDIDATE = 'APPROVED_CREATE_CANDIDATE';
    public const NOT_A_SPECIFICATION_CANDIDATE = 'NOT_A_SPECIFICATION_CANDIDATE';
    public const REVIEW = 'REVIEW';
    public const REJECT_CANDIDATE = 'REJECT_CANDIDATE';

    private const EXPECTED_CABLE_PRODUCTS = 54;
    private const EXPECTED_DEFINITIONS = 55;
    private const EXPECTED_STYLE_FIELD_MAPPINGS = 483;
    private const EXPECTED_FIELD_MAPPINGS = 506;
    private const ACCESSORY_SPEC_FIELD_MAPPING_READY = 27;

    private const ALWAYS_POPULATED_CODES = ['cc', 'mt', 'ct'];

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function resolve(array $inputs): array
    {
        $d1 = is_array($inputs['d1Package'] ?? null) ? $inputs['d1Package'] : [];
        $readyProducts = $this->filterCableProducts($inputs['classificationReadyProducts'] ?? []);
        $evidence = is_array($inputs['investigationEvidence'] ?? null)
            ? $inputs['investigationEvidence']
            : CableSpecBatchD2InvestigationCollector::embeddedEvidence();
        $forbiddenAttempts = (int) ($inputs['forbiddenWriteAttempts'] ?? 0);

        $discovered = count($readyProducts);
        $discrepancy = $discovered !== self::EXPECTED_CABLE_PRODUCTS;
        $packageStatus = $discrepancy ? 'NOT_READY_FOR_OPERATOR_REVIEW' : 'READY_FOR_OPERATOR_DECISIONS';

        $evidence = $this->mergeDefaultEvidence($evidence);
        $stats = $this->extractStats($d1, $evidence);
        $presence = is_array($evidence['styleFieldPresence'] ?? null) ? $evidence['styleFieldPresence'] : [];

        $candidates = $this->buildOperatorCandidates($d1, $evidence, $stats);
        $candidateCounts = $this->countBy($candidates, 'decisionCandidate');
        $confidenceCounts = $this->countBy($candidates, 'confidence');

        $criticalFindings = $this->buildCriticalFindings($evidence, $candidates);
        $correlations = $this->buildCorrelations($evidence);
        $ambient = $this->buildAmbientInvestigation($candidates, $stats);
        $earthCsa = $this->buildEarthCsaValidation($evidence, $candidates);
        $semanticGuards = $this->buildSemanticGuards($candidates);

        $blockerMatrix = $this->buildBlockerMatrix($readyProducts, $candidates, $stats, $presence);
        $readinessProjection = $this->buildReadinessProjection(
            $readyProducts,
            $candidates,
            $stats,
            $presence,
            $blockerMatrix,
        );

        $batchC2Integrity = $this->buildBatchC2Integrity($d1);
        $artifacts = [
            'cable-spec-d2-semantic-investigation.json',
            'cable-spec-d2-code-correlations.json',
            'cable-spec-d2-operator-candidates.json',
            'cable-spec-d2-blocker-matrix.json',
            'cable-spec-d2-readiness-projection.json',
            'cable-spec-d2-final-review-package.json',
        ];

        return [
            'phase' => '12.7-batch-d2-cable-spec-operator-review',
            'mode' => 'READ_INVESTIGATE_REVIEW_ONLY',
            'packageStatus' => $packageStatus,
            'cableSpecApply' => 'NOT_AVAILABLE',
            'approvalBoundary' => [
                'cableSpecD2ApplyGuard' => CableSpecBatchD2CliGuard::APPLY_NOT_AVAILABLE,
                'operatorDecisionsRequired' => true,
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => $forbiddenAttempts,
            ],
            'targetSafety' => [
                'definitionCreates' => 0,
                'optionCreates' => 0,
                'unitCreates' => 0,
                'fieldMappingInserts' => 0,
                'productSpecValueWrites' => 0,
                'variantSpecValueWrites' => 0,
                'totalWrites' => 0,
            ],
            'cablePopulation' => [
                'expected' => self::EXPECTED_CABLE_PRODUCTS,
                'discovered' => $discovered,
                'discrepancy' => $discrepancy,
                'status' => $discrepancy ? 'BLOCKED_POPULATION_MISMATCH' : 'READY',
            ],
            'semanticGuards' => $semanticGuards,
            'criticalFindings' => $criticalFindings,
            'correlations' => $correlations,
            'ambientCurrentInvestigation' => $ambient,
            'earthCsaTypeValidation' => $earthCsa,
            'operatorCandidates' => [
                'count' => count($candidates),
                'items' => $candidates,
                'counts' => $candidateCounts,
            ],
            'candidateCounts' => $candidateCounts,
            'confidenceCounts' => $confidenceCounts,
            'blockerMatrix' => $blockerMatrix,
            'readinessProjection' => $readinessProjection,
            'batchC2Integrity' => $batchC2Integrity,
            'd1Reuse' => [
                'phase' => (string) ($d1['phase'] ?? '12.7-batch-d1-cable-spec-field-mapping'),
                'packageStatus' => (string) ($d1['packageStatus'] ?? ''),
                'resolveCableSpecFieldMappingServiceReplaced' => false,
            ],
            'investigationEvidenceMode' => (string) ($evidence['mode'] ?? 'UNKNOWN'),
            'schemaInvestigation' => [
                'selectOnly' => true,
                'queriesWereSelectOnly' => (bool) ($evidence['queriesWereSelectOnly'] ?? true),
                'notes' => is_array($evidence['schemaNotes'] ?? null) ? $evidence['schemaNotes'] : [],
            ],
            'artifacts' => $artifacts,
            'recommendation' => $this->buildRecommendation($candidateCounts, $readinessProjection, $criticalFindings),
            'nextAction' => 'AWAIT_OPERATOR_DECISIONS_DO_NOT_START_D3',
            'generatedAtUtc' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function mergeDefaultEvidence(array $evidence): array
    {
        $embedded = CableSpecBatchD2InvestigationCollector::embeddedEvidence();
        if (!isset($evidence['descriptLookup']) || !is_array($evidence['descriptLookup']) || $evidence['descriptLookup'] === []) {
            $evidence['descriptLookup'] = $embedded['descriptLookup'];
        }
        if (!isset($evidence['correlations']) || !is_array($evidence['correlations']) || $evidence['correlations'] === []) {
            $evidence['correlations'] = $embedded['correlations'];
        }
        if (!isset($evidence['sampleInvestigations']) || !is_array($evidence['sampleInvestigations'])) {
            $evidence['sampleInvestigations'] = $embedded['sampleInvestigations'];
        }
        if (!isset($evidence['earthCsaNumeric']) || !is_array($evidence['earthCsaNumeric'])) {
            $evidence['earthCsaNumeric'] = $embedded['earthCsaNumeric'];
        }
        if (!isset($evidence['styleFieldPresence']) || !is_array($evidence['styleFieldPresence'])) {
            $evidence['styleFieldPresence'] = $embedded['styleFieldPresence'];
        }
        if (!isset($evidence['waluMtCross']) || !is_array($evidence['waluMtCross'])) {
            $evidence['waluMtCross'] = $embedded['waluMtCross'];
        }
        $evidence['queriesWereSelectOnly'] = (bool) ($evidence['queriesWereSelectOnly'] ?? true);

        return $evidence;
    }

    /**
     * @param array<string, mixed> $d1
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function extractStats(array $d1, array $evidence): array
    {
        $inventory = is_array($d1['fieldInventory'] ?? null) ? $d1['fieldInventory'] : [];
        $stats = [];
        if (isset($inventory['items']) && is_array($inventory['items'])) {
            foreach ($inventory['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $field = (string) ($item['field'] ?? '');
                if ($field === '') {
                    continue;
                }
                $stats[$field] = [
                    'populated' => (int) ($item['populated'] ?? $item['populatedValues'] ?? 0),
                    'styles' => (int) ($item['styles'] ?? $item['stylesAffected'] ?? 0),
                    'samples' => is_array($item['samples'] ?? null)
                        ? $item['samples']
                        : (is_array($item['sampleValues'] ?? null) ? $item['sampleValues'] : []),
                    'distinct' => (int) ($item['distinct'] ?? 0),
                ];
            }
        }

        // Fallback: concept decisions may carry population hints; prefer live evidence fieldStats.
        $fieldStats = is_array($evidence['fieldStats'] ?? null) ? $evidence['fieldStats'] : [];
        foreach ($fieldStats as $field => $row) {
            if (!is_array($row)) {
                continue;
            }
            $stats[(string) $field] = array_merge($stats[(string) $field] ?? [], [
                'populated' => (int) ($row['populated'] ?? ($stats[(string) $field]['populated'] ?? 0)),
                'styles' => (int) ($row['styles'] ?? ($stats[(string) $field]['styles'] ?? 0)),
            ]);
        }

        // D.1 concept items as last resort for samples.
        $concepts = is_array($d1['conceptDecisions']['items'] ?? null) ? $d1['conceptDecisions']['items'] : [];
        foreach ($concepts as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = (string) ($item['field'] ?? '');
            if ($field === '' || isset($stats[$field])) {
                continue;
            }
            $stats[$field] = [
                'populated' => (int) ($item['populated'] ?? 0),
                'styles' => (int) ($item['styles'] ?? 0),
                'samples' => is_array($item['samples'] ?? null) ? $item['samples'] : [],
                'distinct' => (int) ($item['distinct'] ?? 0),
            ];
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $d1
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $stats
     * @return list<array<string, mixed>>
     */
    private function buildOperatorCandidates(array $d1, array $evidence, array $stats): array
    {
        $d1ByField = [];
        $concepts = is_array($d1['conceptDecisions']['items'] ?? null) ? $d1['conceptDecisions']['items'] : [];
        foreach ($concepts as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = (string) ($item['field'] ?? '');
            if ($field !== '') {
                $d1ByField[$field] = $item;
            }
        }

        // Ensure catalog coverage even if D.1 package truncated.
        foreach ($this->d2DecisionCatalog($evidence) as $field => $meta) {
            if (!isset($d1ByField[$field])) {
                $d1ByField[$field] = [
                    'field' => $field,
                    'concept' => $meta['concept'] ?? $field,
                    'decision' => $meta['d1Decision'] ?? 'REVIEW',
                    'code' => $meta['code'] ?? null,
                ];
            }
        }

        $items = [];
        foreach ($this->d2DecisionCatalog($evidence) as $field => $meta) {
            $d1Item = $d1ByField[$field] ?? [];
            $stat = is_array($stats[$field] ?? null) ? $stats[$field] : [];
            $items[] = [
                'field' => $field,
                'concept' => (string) ($meta['concept'] ?? $d1Item['concept'] ?? $field),
                'd1Decision' => (string) ($d1Item['decision'] ?? $meta['d1Decision'] ?? ''),
                'decisionCandidate' => (string) $meta['decisionCandidate'],
                'confidence' => (string) $meta['confidence'],
                'code' => $meta['code'] ?? ($d1Item['code'] ?? $d1Item['targetDefinitionCode'] ?? null),
                'name' => $meta['name'] ?? null,
                'group' => $meta['group'] ?? null,
                'dataType' => $meta['dataType'] ?? null,
                'typeRecommendation' => $meta['typeRecommendation'] ?? null,
                'unit' => $meta['unit'] ?? null,
                'unitNote' => $meta['unitNote'] ?? null,
                'neverMapTo' => $meta['neverMapTo'] ?? null,
                'reason' => (string) ($meta['reason'] ?? ''),
                'evidence' => is_array($meta['evidence'] ?? null) ? $meta['evidence'] : [(string) ($meta['reason'] ?? '')],
                'populated' => (int) ($stat['populated'] ?? 0),
                'styles' => (int) ($stat['styles'] ?? 0),
                'samples' => is_array($stat['samples'] ?? null) ? array_values($stat['samples']) : [],
                'readinessImpact' => (string) ($meta['readinessImpact'] ?? 'NONE'),
                'd1Changed' => (bool) ($meta['d1Changed'] ?? false),
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) $a['field'], (string) $b['field']),
        );

        return $items;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, array<string, mixed>>
     */
    private function d2DecisionCatalog(array $evidence): array
    {
        $earthNumeric = is_array($evidence['earthCsaNumeric'] ?? null) ? $evidence['earthCsaNumeric'] : [];
        $earthType = (string) ($earthNumeric['typeRecommendation'] ?? 'decimal');
        $samples = is_array($evidence['sampleInvestigations'] ?? null) ? $evidence['sampleInvestigations'] : [];

        return [
            'cable_style' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'IDENTITY',
                'd1Decision' => 'NOT_A_SPECIFICATION',
                'reason' => 'identity_style_key',
                'evidence' => ['Style identity key — not a product specification Definition.'],
                'readinessImpact' => 'RESOLVES_WHEN_EXCLUDED',
            ],
            'my_row_id' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'IDENTITY',
                'd1Decision' => 'NOT_A_SPECIFICATION',
                'reason' => 'surrogate_primary_key',
                'evidence' => ['Surrogate PK — not a specification.'],
                'readinessImpact' => 'RESOLVES_WHEN_EXCLUDED',
            ],
            'height' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'UNUSED',
                'd1Decision' => 'NOT_A_SPECIFICATION',
                'reason' => 'unused_in_cable_population',
                'evidence' => ['Unused in 54-cable population.'],
                'readinessImpact' => 'RESOLVES_WHEN_EXCLUDED',
            ],
            'width' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'UNUSED',
                'd1Decision' => 'NOT_A_SPECIFICATION',
                'reason' => 'unused_in_cable_population',
                'evidence' => ['Unused in 54-cable population.'],
                'readinessImpact' => 'RESOLVES_WHEN_EXCLUDED',
            ],
            'A15c' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'UNUSED',
                'd1Decision' => 'NOT_A_SPECIFICATION',
                'reason' => 'unused_in_cable_population',
                'evidence' => ['Unused ambient current column — confirmed NOT_A_SPECIFICATION.'],
                'readinessImpact' => 'RESOLVES_WHEN_EXCLUDED',
            ],
            'A25c' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'UNUSED',
                'd1Decision' => 'NOT_A_SPECIFICATION',
                'reason' => 'unused_in_cable_population',
                'evidence' => ['Unused ambient current column — confirmed NOT_A_SPECIFICATION.'],
                'readinessImpact' => 'RESOLVES_WHEN_EXCLUDED',
            ],
            'cap' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'UNUSED',
                'd1Decision' => 'NOT_A_SPECIFICATION',
                'reason' => 'unused_in_cable_population',
                'evidence' => ['Unused in 54-cable population.'],
                'readinessImpact' => 'RESOLVES_WHEN_EXCLUDED',
            ],
            'core_con' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'UNUSED',
                'd1Decision' => 'NOT_A_SPECIFICATION',
                'reason' => 'unused_in_cable_population',
                'evidence' => ['Unused in 54-cable population.'],
                'readinessImpact' => 'RESOLVES_WHEN_EXCLUDED',
            ],
            'mt' => [
                'decisionCandidate' => self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'MEASUREMENT_SYSTEM',
                'd1Decision' => 'REVIEW',
                'd1Changed' => true,
                'reason' => 'MEASUREMENT_SYSTEM_METADATA',
                'evidence' => [
                    'descript.desc_id 1301 → Metric System (code M, desc_type 13)',
                    'descript.desc_id 1302 → Imperial System (code I)',
                    '0 → unset/null',
                    (string) ($samples['mt_bh'] ?? 'BH xa=10 mt=1301 od≈10.2mm vs mt=1302 od≈0.37in'),
                    'walu/wbnz only non-zero when mt=1302',
                    'Do NOT migrate raw IDs as specification values.',
                ],
                'readinessImpact' => 'BLOCKS_ALL_54_UNTIL_EXCLUDED',
            ],
            'nc' => [
                'decisionCandidate' => self::APPROVED_MAP_EXISTING_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'CORES',
                'code' => 'CORES',
                'name' => 'Number of cores',
                'group' => 'construction',
                'dataType' => 'integer',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'reason' => 'integer_cores',
                'evidence' => ['Confirmed map-existing to CORES.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'xa' => [
                'decisionCandidate' => self::APPROVED_MAP_EXISTING_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'CSA',
                'code' => 'CSA',
                'name' => 'Conductor CSA',
                'group' => 'construction',
                'dataType' => 'text',
                'unit' => 'mm2',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'reason' => 'conductor_csa_mm2',
                'evidence' => ['Confirmed map-existing to CSA.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'od' => [
                'decisionCandidate' => self::APPROVED_MAP_EXISTING_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'NOM_CABLE_OD',
                'code' => 'NOM-CABLE-OD',
                'name' => 'Nom. Cable',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'neverMapTo' => 'OUTER-DIAMETER',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'reason' => 'cable_od_mm_maps_to_nom_cable_od',
                'evidence' => ['od → NOM-CABLE-OD; never OUTER-DIAMETER.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'cdia' => [
                'decisionCandidate' => self::APPROVED_MAP_EXISTING_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'NOM_COND_OD',
                'code' => 'NOM-COND-OD',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'reason' => 'conductor_od_mm',
                'evidence' => ['Confirmed map-existing to NOM-COND-OD.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'od_ins' => [
                'decisionCandidate' => self::APPROVED_MAP_EXISTING_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'NOM_INS_OD',
                'code' => 'NOM-INS-OD',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'reason' => 'insulation_od_mm',
                'evidence' => ['Confirmed map-existing to NOM-INS-OD.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'br' => [
                'decisionCandidate' => self::APPROVED_MAP_EXISTING_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'MIN_BEND_RADIUS',
                'code' => 'MIN-BEND-RADIUS',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'reason' => 'min_bend_radius',
                'evidence' => ['Confirmed map-existing to MIN-BEND-RADIUS.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'wght' => [
                'decisionCandidate' => self::APPROVED_MAP_EXISTING_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'APPROX_WEIGHT',
                'code' => 'APPROX-WEIGHT',
                'dataType' => 'decimal',
                'unit' => 'kg/km',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'reason' => 'approx_weight_kg_per_km',
                'evidence' => ['Confirmed map-existing to APPROX-WEIGHT (distinct from walu/wbnz).'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'A30c' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'HIGH',
                'concept' => 'CURRENT_AMBIENT_30C',
                'code' => 'CURRENT',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'd1Changed' => true,
                'reason' => 'AMBIENT_CURRENT_SEMANTIC_COLLISION',
                'evidence' => [
                    'Mapping A30c/A40c/A45c all to CURRENT loses ambient temperature semantics.',
                    'Operator must choose separate CREATE CURRENT-30C/40C/45C or structured ambient model.',
                ],
                'readinessImpact' => 'BLOCKS_STYLES_WITH_AMBIENT_CURRENT',
            ],
            'A40c' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'HIGH',
                'concept' => 'CURRENT_AMBIENT_40C',
                'code' => 'CURRENT',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'd1Changed' => true,
                'reason' => 'AMBIENT_CURRENT_SEMANTIC_COLLISION',
                'evidence' => [
                    'Mapping A30c/A40c/A45c all to CURRENT loses ambient temperature semantics.',
                ],
                'readinessImpact' => 'BLOCKS_STYLES_WITH_AMBIENT_CURRENT',
            ],
            'A45c' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'HIGH',
                'concept' => 'CURRENT_AMBIENT_45C',
                'code' => 'CURRENT',
                'd1Decision' => 'PROPOSED_MAP_EXISTING',
                'd1Changed' => true,
                'reason' => 'AMBIENT_CURRENT_SEMANTIC_COLLISION',
                'evidence' => [
                    'Mapping A30c/A40c/A45c all to CURRENT loses ambient temperature semantics.',
                ],
                'readinessImpact' => 'BLOCKS_STYLES_WITH_AMBIENT_CURRENT',
            ],
            'ne' => [
                'decisionCandidate' => self::APPROVED_CREATE_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'EARTH_CORES',
                'code' => 'EARTH-CORES',
                'name' => 'Earth cores',
                'group' => 'construction',
                'dataType' => 'integer',
                'd1Decision' => 'PROPOSED_CREATE',
                'reason' => 'earth_core_count_mechanical_construction',
                'evidence' => ['Confirmed create candidate EARTH-CORES.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'xae' => [
                'decisionCandidate' => self::APPROVED_CREATE_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'EARTH_CSA',
                'code' => 'EARTH-CSA',
                'name' => 'Earth CSA',
                'group' => 'construction',
                'dataType' => 'decimal',
                'typeRecommendation' => $earthType,
                'unit' => 'mm2',
                'd1Decision' => 'PROPOSED_CREATE',
                'd1Changed' => true,
                'reason' => 'earth_conductor_csa_mm2_prefer_decimal',
                'evidence' => [
                    'All xae values numeric (' . (int) ($earthNumeric['numericCompatible'] ?? 4491)
                    . '/' . (int) ($earthNumeric['populated'] ?? 4491) . ').',
                    'D.1 proposed text; D.2 recommends decimal.',
                ],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'ode_ins' => [
                'decisionCandidate' => self::APPROVED_CREATE_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'EARTH_INS_OD',
                'code' => 'EARTH-INS-OD',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'd1Decision' => 'PROPOSED_CREATE',
                'reason' => 'earth_insulation_od_mm',
                'evidence' => ['Confirmed create candidate EARTH-INS-OD.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'od_bed' => [
                'decisionCandidate' => self::APPROVED_CREATE_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'BEDDING_OD',
                'code' => 'BEDDING-OD',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'd1Decision' => 'PROPOSED_CREATE',
                'reason' => 'bedding_od_mm',
                'evidence' => ['Confirmed create candidate BEDDING-OD.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'od_arm' => [
                'decisionCandidate' => self::APPROVED_CREATE_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'ARMOUR_OD',
                'code' => 'ARMOUR-OD',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'd1Decision' => 'PROPOSED_CREATE',
                'reason' => 'armour_od_mm',
                'evidence' => ['Confirmed create candidate ARMOUR-OD.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'od_brd' => [
                'decisionCandidate' => self::APPROVED_CREATE_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'BRAID_OD',
                'code' => 'BRAID-OD',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'd1Decision' => 'PROPOSED_CREATE',
                'reason' => 'braid_od_mm',
                'evidence' => ['Confirmed create candidate BRAID-OD.'],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'cc' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'concept' => 'CORE_CONFIGURATION_MODE',
                'd1Decision' => 'REVIEW',
                'reason' => 'CORE_CONFIGURATION_INFERRED_NOT_PROVEN',
                'evidence' => [
                    'Values c/p/t/a.',
                    (string) ($samples['cc_patterns'] ?? 'c=cores; p=pairs; t=triples; a=AWG-like'),
                    'Inferred core-configuration / counting mode — not fully proven.',
                    'Do not auto-create options.',
                ],
                'readinessImpact' => 'BLOCKS_ALL_54',
            ],
            'ct' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'concept' => 'CONDUCTOR_TYPE',
                'd1Decision' => 'REVIEW',
                'reason' => 'CONDUCTOR_TYPE_INFERRED_NOT_PROVEN',
                'evidence' => [
                    'Values p/a.',
                    (string) ($samples['ct_kl'] ?? 'KL ct=a lighter + larger OD vs ct=p'),
                    'Consistent with aluminium vs copper; rating tables use C/A (different alphabet).',
                    'Do not auto-create options.',
                ],
                'readinessImpact' => 'BLOCKS_ALL_54',
            ],
            'ins' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'concept' => 'INSULATION',
                'd1Decision' => 'REVIEW',
                'reason' => 'TYPE_AMBIGUITY',
                'evidence' => [
                    (string) ($samples['ins_ambiguity'] ?? 'thickness vs OD ambiguity across styles'),
                ],
                'readinessImpact' => 'BLOCKS_STYLES_WITH_INS',
            ],
            'walu' => [
                'decisionCandidate' => self::APPROVED_CREATE_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'APPROX_WEIGHT_ALU',
                'code' => 'APPROX-WEIGHT-ALU',
                'name' => 'Approx. Aluminium Weight',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unitNote' => 'KG_PER_KM_OR_IMPERIAL — resolve via mt (1301→kg/km, 1302→imperial mass/length)',
                'd1Decision' => 'REVIEW',
                'd1Changed' => true,
                'reason' => 'aluminium_weight_distinct_from_approx_weight',
                'evidence' => [
                    'Only non-zero when mt=1302 (imperial) in population cross-check; parallel numeric weights alongside wght.',
                    'Do NOT map to APPROX-WEIGHT.',
                    'Unit selection uses mt metadata without writing conversions.',
                ],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'wbnz' => [
                'decisionCandidate' => self::APPROVED_CREATE_CANDIDATE,
                'confidence' => 'HIGH',
                'concept' => 'APPROX_WEIGHT_BRONZE',
                'code' => 'APPROX-WEIGHT-BRONZE',
                'name' => 'Approx. Bronze Weight',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unitNote' => 'KG_PER_KM_OR_IMPERIAL — resolve via mt',
                'd1Decision' => 'REVIEW',
                'd1Changed' => true,
                'reason' => 'bronze_weight_distinct_from_approx_weight',
                'evidence' => [
                    'Only non-zero when mt=1302; parallel numeric weights alongside wght.',
                    'Do NOT map to APPROX-WEIGHT.',
                ],
                'readinessImpact' => 'UNLOCKS_WHEN_APPROVED',
            ],
            'Af' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'LOW',
                'concept' => 'CURRENT_AF',
                'd1Decision' => 'REVIEW',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
                'evidence' => [
                    (string) ($samples['b1_engineering'] ?? 'B1 only (11 rows)'),
                    'Scales with size near current ratings — earth-fault/form-factor uncertain.',
                ],
                'readinessImpact' => 'BLOCKS_B1_ONLY',
            ],
            'r' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'concept' => 'AC_RESISTANCE',
                'd1Decision' => 'REVIEW',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
                'evidence' => [
                    'AC resistance inferred (decreases with CSA); B1 only.',
                ],
                'readinessImpact' => 'BLOCKS_B1_ONLY',
            ],
            'x50' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'concept' => 'REACTANCE_50',
                'd1Decision' => 'REVIEW',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
                'evidence' => ['Reactance @ 50 Hz inferred; B1 only.'],
                'readinessImpact' => 'BLOCKS_B1_ONLY',
            ],
            'x60' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'concept' => 'REACTANCE_60',
                'd1Decision' => 'REVIEW',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
                'evidence' => ['Reactance @ 60 Hz inferred; B1 only.'],
                'readinessImpact' => 'BLOCKS_B1_ONLY',
            ],
            'tension' => [
                'decisionCandidate' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'concept' => 'PULLING_TENSION',
                'd1Decision' => 'REVIEW',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
                'evidence' => ['Pulling tension scales with size; B1 only.'],
                'readinessImpact' => 'BLOCKS_B1_ONLY',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function buildCriticalFindings(array $evidence, array $candidates): array
    {
        $byField = [];
        foreach ($candidates as $c) {
            $byField[(string) $c['field']] = $c;
        }

        $descript = is_array($evidence['descriptLookup'] ?? null) ? $evidence['descriptLookup'] : [];

        return [
            'cc' => [
                'meaning' => 'Inferred core-configuration / counting mode (cores/pairs/triples/AWG-like) — not fully proven',
                'values' => ['c', 'p', 't', 'a'],
                'decision' => $byField['cc']['decisionCandidate'] ?? self::REVIEW,
                'confidence' => $byField['cc']['confidence'] ?? 'MEDIUM',
                'evidence' => $byField['cc']['evidence'] ?? [],
            ],
            'mt' => [
                'meaning' => 'Measurement-system metadata for interpreting units of other fields — NOT a product specification Definition',
                '1301' => 'Metric System (code M, desc_type 13)',
                '1302' => 'Imperial System (code I)',
                '0' => 'unset/null',
                'descriptLookup' => $descript,
                'decision' => $byField['mt']['decisionCandidate'] ?? self::NOT_A_SPECIFICATION_CANDIDATE,
                'confidence' => $byField['mt']['confidence'] ?? 'HIGH',
                'evidence' => $byField['mt']['evidence'] ?? [],
            ],
            'ct' => [
                'meaning' => 'Inferred conductor type (copper vs aluminium-ish) — not fully proven; do not auto-create options',
                'p' => 'Likely copper / default conductor type',
                'a' => 'Likely aluminium conductor type',
                'decision' => $byField['ct']['decisionCandidate'] ?? self::REVIEW,
                'confidence' => $byField['ct']['confidence'] ?? 'MEDIUM',
                'evidence' => $byField['ct']['evidence'] ?? [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function buildCorrelations(array $evidence): array
    {
        $matrix = is_array($evidence['correlations'] ?? null) ? $evidence['correlations'] : [];

        return [
            'dimensions' => ['cc', 'mt', 'ct'],
            'interpretation' => 'Three related but not identical dimensions (config mode × unit system × conductor type-ish). Do not create 3 Definitions automatically.',
            'matrix' => $matrix,
            'combinationCount' => count($matrix),
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function buildAmbientInvestigation(array $candidates, array $stats): array
    {
        $byField = [];
        foreach ($candidates as $c) {
            $byField[(string) $c['field']] = $c;
        }

        $fields = ['A15c', 'A25c', 'A30c', 'A40c', 'A45c', 'Af'];
        $items = [];
        foreach ($fields as $field) {
            $c = $byField[$field] ?? [];
            $stat = is_array($stats[$field] ?? null) ? $stats[$field] : [];
            $items[$field] = [
                'decisionCandidate' => $c['decisionCandidate'] ?? null,
                'confidence' => $c['confidence'] ?? null,
                'reason' => $c['reason'] ?? null,
                'populated' => (int) ($stat['populated'] ?? $c['populated'] ?? 0),
                'styles' => (int) ($stat['styles'] ?? $c['styles'] ?? 0),
                'd1Decision' => $c['d1Decision'] ?? null,
            ];
        }

        return [
            'collisionDetected' => true,
            'collisionReason' => 'AMBIENT_CURRENT_SEMANTIC_COLLISION',
            'recommendation' => 'Return A30c/A40c/A45c to REVIEW until operator chooses separate CURRENT-30C/40C/45C Definitions or a structured ambient model. Do not keep as map-existing candidates. Af is separate from ambient columns.',
            'singleCurrentDefinitionSufficient' => false,
            'fields' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function buildEarthCsaValidation(array $evidence, array $candidates): array
    {
        $numeric = is_array($evidence['earthCsaNumeric'] ?? null) ? $evidence['earthCsaNumeric'] : [];
        $xae = null;
        foreach ($candidates as $c) {
            if (($c['field'] ?? '') === 'xae') {
                $xae = $c;
                break;
            }
        }

        return [
            'field' => 'xae',
            'code' => 'EARTH-CSA',
            'd1DataType' => 'text',
            'typeRecommendation' => (string) ($xae['typeRecommendation'] ?? $numeric['typeRecommendation'] ?? 'decimal'),
            'populated' => (int) ($numeric['populated'] ?? 0),
            'numericCompatible' => (int) ($numeric['numericCompatible'] ?? 0),
            'decisionCandidate' => $xae['decisionCandidate'] ?? self::APPROVED_CREATE_CANDIDATE,
            'confidence' => $xae['confidence'] ?? 'HIGH',
            'note' => 'All meaningful xae values are numeric — recommend decimal (not text).',
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function buildSemanticGuards(array $candidates): array
    {
        $odOk = false;
        $odNeverOuter = true;
        $noInd = true;
        foreach ($candidates as $c) {
            $code = strtoupper((string) ($c['code'] ?? ''));
            if (($c['field'] ?? '') === 'od') {
                $odOk = $code === 'NOM-CABLE-OD';
                $never = strtoupper((string) ($c['neverMapTo'] ?? 'OUTER-DIAMETER'));
                $odNeverOuter = $never === 'OUTER-DIAMETER' && $code !== 'OUTER-DIAMETER';
            }
            if ($code === 'IND-LSZH-FIRE') {
                $noInd = false;
            }
        }

        return [
            'odMapsToNomCableOd' => $odOk,
            'odNeverOuterDiameter' => $odNeverOuter,
            'noIndLszhFire' => $noInd,
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function buildBlockerMatrix(array $products, array $candidates, array $stats, array $presence): array
    {
        $unresolved = [];
        foreach ($candidates as $c) {
            $field = (string) $c['field'];
            $decision = (string) ($c['decisionCandidate'] ?? '');
            // Current blockers: REVIEW fields, plus critical codes until operator confirms
            // (mt is NOT_A_SPECIFICATION_CANDIDATE but still blocks until that exclusion is accepted).
            if ($decision === self::REVIEW || in_array($field, self::ALWAYS_POPULATED_CODES, true)) {
                $unresolved[$field] = $c;
            }
        }

        $perProduct = [];
        $conceptCounts = [];
        foreach (array_keys($unresolved) as $field) {
            $conceptCounts[$field] = 0;
        }

        foreach ($products as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $blockers = [];
            foreach ($unresolved as $field => $c) {
                if ($this->fieldBlocksStyle($field, $style, $c, $stats, $presence, count($products))) {
                    $blockers[] = $field;
                    ++$conceptCounts[$field];
                }
            }
            sort($blockers);
            $perProduct[] = [
                'styleCode' => $style,
                'productTitle' => MigrationPayload::string($p['productTitle'] ?? $p['title'] ?? '') ?: null,
                'blockingConcepts' => $blockers,
                'blockingCount' => count($blockers),
            ];
        }

        usort(
            $perProduct,
            static fn (array $a, array $b): int => strcmp((string) $a['styleCode'], (string) $b['styleCode']),
        );

        arsort($conceptCounts);
        $summary = [];
        foreach ($conceptCounts as $concept => $count) {
            $summary[] = [
                'concept' => $concept,
                'productsBlocked' => $count,
                'confidence' => $unresolved[$concept]['confidence'] ?? null,
                'reason' => $unresolved[$concept]['reason'] ?? null,
            ];
        }

        return [
            'products' => $perProduct,
            'conceptSummary' => $summary,
            'criticalCodeBlockers' => [
                'cc' => $conceptCounts['cc'] ?? 0,
                'mt' => $conceptCounts['mt'] ?? 0,
                'ct' => $conceptCounts['ct'] ?? 0,
            ],
            'note' => 'Unresolved REVIEW concepts only. APPROVED_* / NOT_A_SPECIFICATION candidates are not blockers in this matrix.',
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     */
    private function fieldBlocksStyle(
        string $field,
        string $style,
        array $candidate,
        array $stats,
        array $presence,
        int $productCount,
    ): bool {
        $populated = (int) ($candidate['populated'] ?? ($stats[$field]['populated'] ?? 0));
        $styles = (int) ($candidate['styles'] ?? ($stats[$field]['styles'] ?? 0));
        if ($populated <= 0 && $styles <= 0 && !isset($presence[$field])) {
            // Always-populated critical codes still block even if stats missing.
            return in_array($field, self::ALWAYS_POPULATED_CODES, true);
        }
        if ($populated <= 0 && !isset($presence[$field]) && !in_array($field, self::ALWAYS_POPULATED_CODES, true)) {
            return false;
        }

        if (in_array($field, self::ALWAYS_POPULATED_CODES, true)) {
            return true;
        }

        if (isset($presence[$field])) {
            $list = array_map(static fn ($s): string => strtoupper(trim((string) $s)), $presence[$field]);

            return in_array($style, $list, true);
        }

        // Without a presence map: if the field is populated anywhere, conservatively treat it
        // as blocking every product (matches D.1 global REVIEW behaviour). Prefer supplying
        // styleFieldPresence from the collector for sparse fields.
        if ($populated > 0 || $styles > 0) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     * @param array<string, mixed> $blockerMatrix
     * @return array<string, mixed>
     */
    private function buildReadinessProjection(
        array $products,
        array $candidates,
        array $stats,
        array $presence,
        array $blockerMatrix,
    ): array {
        $productCount = count($products);

        $current = [
            'READY' => 0,
            'REVIEW' => $productCount,
            'BLOCKED' => 0,
            'CABLE_SPEC_FIELD_MAPPING_READY' => 0,
            'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
            'ACCESSORY_DV_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
            'note' => 'No operator approvals applied yet — all REVIEW fields remain unresolved.',
        ];

        $high = $this->simulateReadiness($products, $candidates, $stats, $presence, [
            'resolveDecisions' => [self::APPROVED_MAP_EXISTING_CANDIDATE, self::APPROVED_CREATE_CANDIDATE, self::NOT_A_SPECIFICATION_CANDIDATE],
            'requireHighConfidence' => true,
            'forceResolveFields' => [],
        ]);

        $all = $this->simulateReadiness($products, $candidates, $stats, $presence, [
            'resolveDecisions' => [self::APPROVED_MAP_EXISTING_CANDIDATE, self::APPROVED_CREATE_CANDIDATE, self::NOT_A_SPECIFICATION_CANDIDATE],
            'requireHighConfidence' => false,
            'forceResolveFields' => [],
        ]);

        $critical = $this->simulateReadiness($products, $candidates, $stats, $presence, [
            'resolveDecisions' => [self::APPROVED_MAP_EXISTING_CANDIDATE, self::APPROVED_CREATE_CANDIDATE, self::NOT_A_SPECIFICATION_CANDIDATE],
            'requireHighConfidence' => true,
            'forceResolveFields' => ['cc', 'mt', 'ct'],
        ]);

        // Fill concept summary productsBlocked for sparse fields lacking presence using styles count.
        $conceptSummary = is_array($blockerMatrix['conceptSummary'] ?? null) ? $blockerMatrix['conceptSummary'] : [];
        foreach ($conceptSummary as &$row) {
            $concept = (string) ($row['concept'] ?? '');
            if (($row['productsBlocked'] ?? 0) === 0) {
                $styles = (int) ($stats[$concept]['styles'] ?? 0);
                foreach ($candidates as $c) {
                    if (($c['field'] ?? '') === $concept) {
                        $styles = max($styles, (int) ($c['styles'] ?? 0));
                    }
                }
                if ($styles > 0) {
                    $row['productsBlocked'] = $styles;
                    $row['productsBlockedNote'] = 'style_count_fallback_without_presence_map';
                }
            }
        }
        unset($row);

        return [
            'current' => $current,
            'highConfidence' => $high,
            'allCandidates' => $all,
            'ifCcMtCtResolved' => array_merge($critical, [
                'note' => 'Hypothetical: treat cc+mt+ct as resolved and HIGH APPROVED_*/NOT_A_SPEC as approved. Ambient A30c/A40c/A45c remain REVIEW blockers where populated.',
            ]),
            'projected' => [
                'CABLE_SPEC_FIELD_MAPPING_READY' => (int) ($high['READY'] ?? 0),
                'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY + (int) ($high['READY'] ?? 0),
            ],
            'blockerNote' => 'HIGH projection READY remains 0 while cc/ct stay REVIEW (always populated).',
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    private function simulateReadiness(
        array $products,
        array $candidates,
        array $stats,
        array $presence,
        array $opts,
    ): array {
        $resolveDecisions = is_array($opts['resolveDecisions'] ?? null) ? $opts['resolveDecisions'] : [];
        $requireHigh = (bool) ($opts['requireHighConfidence'] ?? false);
        $forceResolve = is_array($opts['forceResolveFields'] ?? null) ? $opts['forceResolveFields'] : [];

        $ready = 0;
        $review = 0;
        $stillBlocking = [];

        foreach ($products as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $blockers = [];
            foreach ($candidates as $c) {
                $field = (string) ($c['field'] ?? '');
                $decision = (string) ($c['decisionCandidate'] ?? '');
                $confidence = strtoupper((string) ($c['confidence'] ?? ''));

                $resolved = in_array($field, $forceResolve, true);
                if (!$resolved && in_array($decision, $resolveDecisions, true)) {
                    if (!$requireHigh || $confidence === 'HIGH') {
                        $resolved = true;
                    }
                }

                if ($resolved) {
                    continue;
                }

                // Unresolved meaningful field: REVIEW always blocks when populated for style;
                // also any non-approved leftover.
                if ($decision === self::REJECT_CANDIDATE) {
                    continue;
                }

                if ($this->fieldBlocksStyle($field, $style, $c, $stats, $presence, count($products))) {
                    // Only count as blocker if not an approved-path leftover with empty population
                    if ($decision === self::REVIEW || !in_array($decision, $resolveDecisions, true)) {
                        $blockers[] = $field;
                    }
                } elseif ($decision === self::REVIEW && in_array($field, self::ALWAYS_POPULATED_CODES, true)) {
                    $blockers[] = $field;
                }
            }

            if ($blockers === []) {
                ++$ready;
            } else {
                ++$review;
                foreach ($blockers as $b) {
                    $stillBlocking[$b] = ($stillBlocking[$b] ?? 0) + 1;
                }
            }
        }

        arsort($stillBlocking);

        return [
            'READY' => $ready,
            'REVIEW' => $review,
            'BLOCKED' => 0,
            'CABLE_SPEC_FIELD_MAPPING_READY' => $ready,
            'stillBlockingConcepts' => $stillBlocking,
        ];
    }

    /**
     * @param array<string, mixed> $d1
     * @return array<string, mixed>
     */
    private function buildBatchC2Integrity(array $d1): array
    {
        $integrity = is_array($d1['batchC2Integrity'] ?? null) ? $d1['batchC2Integrity'] : [];
        $target = is_array($d1['target'] ?? null) ? $d1['target'] : [];

        $definitions = (int) ($integrity['definitions'] ?? $target['definitions'] ?? self::EXPECTED_DEFINITIONS);
        $styleMappings = (int) ($integrity['styleMappings'] ?? $target['styleFieldMappings'] ?? self::EXPECTED_STYLE_FIELD_MAPPINGS);
        $totalMappings = (int) ($integrity['totalMappings'] ?? $target['fieldMappings'] ?? self::EXPECTED_FIELD_MAPPINGS);

        return [
            'definitions' => $definitions,
            'styleMappings' => $styleMappings,
            'totalMappings' => $totalMappings,
            'mappingsModified' => 0,
            'definitionsModified' => 0,
            'specificationValuesModified' => 0,
            'driftDetected' => $definitions !== self::EXPECTED_DEFINITIONS
                || $styleMappings !== self::EXPECTED_STYLE_FIELD_MAPPINGS
                || ($totalMappings > 0 && $totalMappings !== self::EXPECTED_FIELD_MAPPINGS),
            'echoFromD1' => true,
        ];
    }

    /**
     * @param array<string, int> $candidateCounts
     * @param array<string, mixed> $readinessProjection
     * @param array<string, mixed> $criticalFindings
     * @return array<string, mixed>
     */
    private function buildRecommendation(array $candidateCounts, array $readinessProjection, array $criticalFindings): array
    {
        $highReady = (int) ($readinessProjection['highConfidence']['READY'] ?? 0);
        $criticalReady = (int) ($readinessProjection['ifCcMtCtResolved']['READY'] ?? 0);

        return [
            'status' => 'READY_FOR_OPERATOR_DECISIONS',
            'summary' => 'D.2 operator-review package prepared. No apply/create/write performed.',
            'candidateCounts' => $candidateCounts,
            'criticalCodes' => [
                'cc' => $criticalFindings['cc']['decision'] ?? null,
                'mt' => $criticalFindings['mt']['decision'] ?? null,
                'ct' => $criticalFindings['ct']['decision'] ?? null,
            ],
            'highConfidenceReady' => $highReady,
            'ifCcMtCtResolvedReady' => $criticalReady,
            'ambientRecommendation' => 'Keep A30c/A40c/A45c as REVIEW (AMBIENT_CURRENT_SEMANTIC_COLLISION).',
            'earthCsaTypeRecommendation' => 'decimal',
            'doNotStartD3' => true,
        ];
    }

    /**
     * @param mixed $products
     * @return list<array<string, mixed>>
     */
    private function filterCableProducts(mixed $products): array
    {
        if (!is_array($products)) {
            return [];
        }
        $out = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (strtoupper((string) ($p['family'] ?? '')) !== 'CABLE') {
                continue;
            }
            $style = strtoupper(trim((string) ($p['style_code'] ?? $p['styleCode'] ?? '')));
            if ($style === '') {
                continue;
            }
            $out[] = $p;
        }

        usort(
            $out,
            static fn (array $a, array $b): int => strcmp(
                strtoupper((string) ($a['style_code'] ?? $a['styleCode'] ?? '')),
                strtoupper((string) ($b['style_code'] ?? $b['styleCode'] ?? '')),
            ),
        );

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, int>
     */
    private function countBy(array $items, string $key): array
    {
        $counts = [];
        foreach ($items as $item) {
            $k = (string) ($item[$key] ?? '');
            if ($k === '') {
                continue;
            }
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
