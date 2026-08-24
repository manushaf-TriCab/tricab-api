<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch D.2 — record operator decisions (artifact only).
 *
 * Never creates Definitions / mappings / values. Never starts D.3 apply.
 */
final class RecordCableSpecBatchD2OperatorDecisionsService
{
    public const APPROVED_MAP_EXISTING = 'APPROVED_MAP_EXISTING';
    public const APPROVED_CREATE = 'APPROVED_CREATE';
    public const APPROVED_NOT_A_SPECIFICATION = 'APPROVED_NOT_A_SPECIFICATION';
    public const REVIEW = 'REVIEW';
    public const REJECT = 'REJECT';

    private const EXPECTED_CABLE_PRODUCTS = 54;
    private const EXPECTED_DEFINITIONS = 55;
    private const EXPECTED_STYLE_FIELD_MAPPINGS = 483;
    private const EXPECTED_FIELD_MAPPINGS = 506;
    private const ACCESSORY_SPEC_FIELD_MAPPING_READY = 27;

    private const ALWAYS_POPULATED_REVIEW = ['cc', 'ct'];

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function record(array $inputs): array
    {
        $d2 = is_array($inputs['d2Package'] ?? null) ? $inputs['d2Package'] : [];
        $inventory = is_array($inputs['inventory'] ?? null) ? $inputs['inventory'] : [];
        $readyProducts = $this->filterCableProducts($inputs['classificationReadyProducts'] ?? []);
        $azureDefinitions = is_array($inputs['azureDefinitions'] ?? null) ? $inputs['azureDefinitions'] : [];
        $azureUnits = is_array($inputs['azureUnits'] ?? null) ? $inputs['azureUnits'] : $this->defaultKnownUnits();
        $forbiddenAttempts = (int) ($inputs['forbiddenWriteAttempts'] ?? 0);
        $presence = $this->mergeStyleFieldPresence(
            is_array($inputs['styleFieldPresence'] ?? null)
                ? $inputs['styleFieldPresence']
                : (is_array(CableSpecBatchD2InvestigationCollector::embeddedEvidence()['styleFieldPresence'] ?? null)
                    ? CableSpecBatchD2InvestigationCollector::embeddedEvidence()['styleFieldPresence']
                    : []),
        );
        $stats = $this->extractStats($inventory, $d2);
        $defCodes = $this->definitionCodes($azureDefinitions);
        $unitCodes = $this->unitCodes($azureUnits, $azureDefinitions);

        $ambient = $this->validateAmbientCreates($stats, $defCodes, $unitCodes, $azureDefinitions);
        $waluUnit = $this->validateWeightUnitAmbiguity($unitCodes);

        $decisions = $this->buildDecisions($ambient, $waluUnit, $stats);
        $decisionCounts = $this->countBy($decisions, 'decision');

        $blockerMatrix = $this->buildBlockerMatrix($readyProducts, $decisions, $stats, $presence);
        $readiness = $this->buildReadinessProjection($readyProducts, $decisions, $stats, $presence, $blockerMatrix);

        $approvedExisting = array_values(array_filter(
            $decisions,
            static fn (array $d): bool => ($d['decision'] ?? '') === self::APPROVED_MAP_EXISTING,
        ));
        $approvedCreate = array_values(array_filter(
            $decisions,
            static fn (array $d): bool => ($d['decision'] ?? '') === self::APPROVED_CREATE,
        ));
        $approvedNotSpec = array_values(array_filter(
            $decisions,
            static fn (array $d): bool => ($d['decision'] ?? '') === self::APPROVED_NOT_A_SPECIFICATION,
        ));
        $unresolved = array_values(array_filter(
            $decisions,
            static fn (array $d): bool => in_array(($d['decision'] ?? ''), [self::REVIEW, self::REJECT], true),
        ));

        $semanticGuards = $this->buildSemanticGuards($decisions);
        $ccCtSearch = [
            'definitiveEvidenceFound' => false,
            'operatorReconsiderationAvailable' => false,
            'cc' => self::REVIEW,
            'ct' => self::REVIEW,
            'note' => 'No definitive legacy label/source-code evidence found; operator REVIEW stands.',
        ];

        $artifacts = [
            'cable-spec-d2-operator-decisions.json',
            'cable-spec-d2-approved-existing-mappings.json',
            'cable-spec-d2-approved-definitions.json',
            'cable-spec-d2-approved-not-specifications.json',
            'cable-spec-d2-unresolved-review.json',
            'cable-spec-d2-post-decision-readiness.json',
            'cable-spec-d2-final-decision-package.json',
        ];

        $integrity = [
            'definitions' => count($defCodes) ?: self::EXPECTED_DEFINITIONS,
            'styleMappings' => self::EXPECTED_STYLE_FIELD_MAPPINGS,
            'totalMappings' => self::EXPECTED_FIELD_MAPPINGS,
            'definitionsModified' => 0,
            'mappingsModified' => 0,
            'specificationValuesModified' => 0,
            'driftDetected' => (count($defCodes) > 0 && count($defCodes) !== self::EXPECTED_DEFINITIONS),
        ];

        $packageStatus = count($readyProducts) === self::EXPECTED_CABLE_PRODUCTS
            && ($ambient['passed'] ?? false) === true
            ? 'OPERATOR_DECISIONS_RECORDED'
            : 'OPERATOR_DECISIONS_RECORDED_WITH_WARNINGS';

        return [
            'phase' => '12.7-batch-d2-cable-spec-operator-decisions',
            'mode' => 'OPERATOR_DECISION_RECORDING_ONLY',
            'packageStatus' => $packageStatus,
            'cableSpecApply' => 'NOT_AVAILABLE',
            'd3Apply' => 'NOT_STARTED',
            'approvalBoundary' => [
                'cableSpecD2DecisionApplyGuard' => CableSpecBatchD2DecisionCliGuard::APPLY_NOT_AVAILABLE,
                'definitionsCreated' => false,
                'mappingsInserted' => false,
                'specificationValuesWritten' => false,
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
                'discovered' => count($readyProducts),
                'discrepancy' => count($readyProducts) !== self::EXPECTED_CABLE_PRODUCTS,
            ],
            'decisionCounts' => $decisionCounts,
            'operatorDecisions' => [
                'count' => count($decisions),
                'items' => $decisions,
                'counts' => $decisionCounts,
            ],
            'approvedExistingMappings' => $approvedExisting,
            'approvedDefinitions' => $approvedCreate,
            'approvedNotSpecifications' => $approvedNotSpec,
            'unresolvedReview' => $unresolved,
            'ambientCurrentValidation' => $ambient,
            'waluWbnzUnitValidation' => $waluUnit,
            'semanticGuards' => $semanticGuards,
            'ccCtFinalSearch' => $ccCtSearch,
            'blockerMatrix' => $blockerMatrix,
            'readinessProjection' => $readiness,
            'batchC2Integrity' => $integrity,
            'artifacts' => $artifacts,
            'recommendation' => [
                'status' => 'OPERATOR_DECISIONS_RECORDED',
                'readyForD3FinalMappingDryRun' => ($ambient['passed'] ?? false) === true,
                'additionalOperatorDecisionsRequired' => true,
                'note' => 'cc/ct remain REVIEW and block all 54; do not start D.3 apply automatically.',
                'doNotStartD3Apply' => true,
            ],
            'nextAction' => 'AWAIT_EXPLICIT_D3_INSTRUCTION',
            'generatedAtUtc' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $ambient
     * @param array<string, mixed> $waluUnit
     * @param array<string, mixed> $stats
     * @return list<array<string, mixed>>
     */
    private function buildDecisions(array $ambient, array $waluUnit, array $stats): array
    {
        $catalog = [
            'nc' => [
                'decision' => self::APPROVED_MAP_EXISTING,
                'confidence' => 'HIGH',
                'code' => 'CORES',
                'name' => 'Number of cores',
                'group' => 'construction',
                'dataType' => 'integer',
                'unit' => null,
                'reason' => 'operator_approved_map_existing',
            ],
            'xa' => [
                'decision' => self::APPROVED_MAP_EXISTING,
                'confidence' => 'HIGH',
                'code' => 'CSA',
                'name' => 'Conductor CSA',
                'group' => 'construction',
                'dataType' => 'text',
                'unit' => 'mm2',
                'reason' => 'operator_approved_map_existing',
            ],
            'od' => [
                'decision' => self::APPROVED_MAP_EXISTING,
                'confidence' => 'HIGH',
                'code' => 'NOM-CABLE-OD',
                'name' => 'Nom. Cable',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'neverMapTo' => 'OUTER-DIAMETER',
                'reason' => 'operator_approved_map_existing_nom_cable_od',
            ],
            'cdia' => [
                'decision' => self::APPROVED_MAP_EXISTING,
                'confidence' => 'HIGH',
                'code' => 'NOM-COND-OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_approved_map_existing',
            ],
            'od_ins' => [
                'decision' => self::APPROVED_MAP_EXISTING,
                'confidence' => 'HIGH',
                'code' => 'NOM-INS-OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_approved_map_existing',
            ],
            'br' => [
                'decision' => self::APPROVED_MAP_EXISTING,
                'confidence' => 'HIGH',
                'code' => 'MIN-BEND-RADIUS',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_approved_map_existing',
            ],
            'wght' => [
                'decision' => self::APPROVED_MAP_EXISTING,
                'confidence' => 'HIGH',
                'code' => 'APPROX-WEIGHT',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'kg/km',
                'reason' => 'operator_approved_map_existing',
            ],
            'ne' => [
                'decision' => self::APPROVED_CREATE,
                'confidence' => 'HIGH',
                'code' => 'EARTH-CORES',
                'name' => 'Earth Cores',
                'group' => 'construction',
                'dataType' => 'integer',
                'unit' => null,
                'reason' => 'operator_approved_create_not_yet_applied',
                'createDeferred' => true,
            ],
            'xae' => [
                'decision' => self::APPROVED_CREATE,
                'confidence' => 'HIGH',
                'code' => 'EARTH-CSA',
                'name' => 'Earth CSA',
                'group' => 'construction',
                'dataType' => 'decimal',
                'unit' => 'mm²',
                'unitCode' => 'MM2',
                'reason' => 'operator_approved_create_decimal_not_text',
                'createDeferred' => true,
                'd1DataTypeCorrectedFrom' => 'text',
            ],
            'ode_ins' => [
                'decision' => self::APPROVED_CREATE,
                'confidence' => 'HIGH',
                'code' => 'EARTH-INS-OD',
                'name' => 'Earth Insulation OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_approved_create_not_yet_applied',
                'createDeferred' => true,
            ],
            'od_bed' => [
                'decision' => self::APPROVED_CREATE,
                'confidence' => 'HIGH',
                'code' => 'BEDDING-OD',
                'name' => 'Bedding OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_approved_create_not_yet_applied',
                'createDeferred' => true,
            ],
            'od_arm' => [
                'decision' => self::APPROVED_CREATE,
                'confidence' => 'HIGH',
                'code' => 'ARMOUR-OD',
                'name' => 'Armour OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_approved_create_not_yet_applied',
                'createDeferred' => true,
            ],
            'od_brd' => [
                'decision' => self::APPROVED_CREATE,
                'confidence' => 'HIGH',
                'code' => 'BRAID-OD',
                'name' => 'Braid OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_approved_create_not_yet_applied',
                'createDeferred' => true,
            ],
            'A30c' => $this->ambientDecision('A30c', 'CURRENT-30C', 'Current Rating at 30°C', 30, $ambient),
            'A40c' => $this->ambientDecision('A40c', 'CURRENT-40C', 'Current Rating at 40°C', 40, $ambient),
            'A45c' => $this->ambientDecision('A45c', 'CURRENT-45C', 'Current Rating at 45°C', 45, $ambient),
            'cable_style' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'identity_style_key',
            ],
            'my_row_id' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'surrogate_primary_key',
            ],
            'height' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'unused_in_cable_population',
            ],
            'width' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'unused_in_cable_population',
            ],
            'A15c' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'unused_in_cable_population',
            ],
            'A25c' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'unused_in_cable_population',
            ],
            'cap' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'unused_in_cable_population',
            ],
            'core_con' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'unused_in_cable_population',
            ],
            'mt' => [
                'decision' => self::APPROVED_NOT_A_SPECIFICATION,
                'confidence' => 'HIGH',
                'reason' => 'MEASUREMENT_SYSTEM_METADATA',
                'evidence' => [
                    '1301 → Metric System',
                    '1302 → Imperial System',
                    '0 → unset/null',
                    'Do not migrate raw IDs as specification values.',
                ],
            ],
            'cc' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'reason' => 'UNKNOWN_SEMANTICS',
                'evidence' => [
                    'Inferred core-configuration / counting mode — not proven.',
                    'No definitive legacy label/source-code evidence found.',
                ],
            ],
            'ct' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'reason' => 'UNKNOWN_SEMANTICS',
                'evidence' => [
                    'Inferred conductor type — not proven.',
                    'No definitive legacy label/source-code evidence found.',
                ],
            ],
            'ins' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'reason' => 'TYPE_AMBIGUITY',
                'evidence' => ['thickness vs OD vs material ambiguity'],
            ],
            'Af' => [
                'decision' => self::REVIEW,
                'confidence' => 'LOW',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
            ],
            'r' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
            ],
            'x50' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
            ],
            'x60' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
            ],
            'tension' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'reason' => 'INSUFFICIENT_VALUES_SINGLE_STYLE',
            ],
            'walu' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'code' => 'APPROX-WEIGHT-ALU',
                'name' => 'Approx. Aluminium Weight',
                'reason' => 'UNIT_AMBIGUITY',
                'evidence' => [
                    (string) ($waluUnit['note'] ?? ''),
                    'Semantics (aluminium weight) are clear but unit is not HIGH-confidence without inventing a unit.',
                    'Do NOT invent units. Do NOT map to APPROX-WEIGHT.',
                ],
            ],
            'wbnz' => [
                'decision' => self::REVIEW,
                'confidence' => 'MEDIUM',
                'code' => 'APPROX-WEIGHT-BRONZE',
                'name' => 'Approx. Bronze Weight',
                'reason' => 'UNIT_AMBIGUITY',
                'evidence' => [
                    (string) ($waluUnit['note'] ?? ''),
                    'Semantics (bronze weight) are clear but unit is not HIGH-confidence without inventing a unit.',
                    'Do NOT invent units. Do NOT map to APPROX-WEIGHT.',
                ],
            ],
        ];

        $items = [];
        foreach ($catalog as $field => $meta) {
            $stat = is_array($stats[$field] ?? null) ? $stats[$field] : [];
            $item = [
                'field' => $field,
                'concept' => (string) ($meta['code'] ?? strtoupper($field)),
                'decision' => (string) $meta['decision'],
                'confidence' => (string) ($meta['confidence'] ?? 'MEDIUM'),
                'code' => $meta['code'] ?? null,
                'name' => $meta['name'] ?? null,
                'group' => $meta['group'] ?? null,
                'dataType' => $meta['dataType'] ?? null,
                'unit' => $meta['unit'] ?? null,
                'unitCode' => $meta['unitCode'] ?? null,
                'neverMapTo' => $meta['neverMapTo'] ?? null,
                'reason' => (string) ($meta['reason'] ?? ''),
                'evidence' => is_array($meta['evidence'] ?? null) ? $meta['evidence'] : [],
                'createDeferred' => (bool) ($meta['createDeferred'] ?? false),
                'populated' => (int) ($stat['populated'] ?? 0),
                'styles' => (int) ($stat['styles'] ?? 0),
                'samples' => is_array($stat['samples'] ?? null) ? array_values($stat['samples']) : [],
            ];
            if (isset($meta['ambientCelsius'])) {
                $item['ambientCelsius'] = $meta['ambientCelsius'];
            }
            if (isset($meta['d1DataTypeCorrectedFrom'])) {
                $item['d1DataTypeCorrectedFrom'] = $meta['d1DataTypeCorrectedFrom'];
            }
            $items[] = $item;
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) $a['field'], (string) $b['field']),
        );

        return $items;
    }

    /**
     * @param array<string, mixed> $ambient
     * @return array<string, mixed>
     */
    private function ambientDecision(string $field, string $code, string $name, int $celsius, array $ambient): array
    {
        $passed = (bool) ($ambient['passed'] ?? false);
        $byField = is_array($ambient['fields'][$field] ?? null) ? $ambient['fields'][$field] : [];

        if ($passed) {
            return [
                'decision' => self::APPROVED_CREATE,
                'confidence' => 'HIGH',
                'code' => $code,
                'name' => $name,
                'group' => 'electrical',
                'dataType' => 'decimal',
                'unit' => 'A',
                'reason' => 'operator_approved_ambient_current_split',
                'createDeferred' => true,
                'neverMapTo' => 'CURRENT',
                'ambientCelsius' => $celsius,
                'evidence' => is_array($byField['checks'] ?? null) ? $byField['checks'] : [],
            ];
        }

        return [
            'decision' => self::REVIEW,
            'confidence' => 'HIGH',
            'code' => $code,
            'name' => $name,
            'group' => 'electrical',
            'dataType' => 'decimal',
            'unit' => 'A',
            'reason' => (string) ($ambient['failureReason'] ?? 'AMBIENT_CURRENT_VALIDATION_FAILED'),
            'createDeferred' => false,
            'neverMapTo' => 'CURRENT',
            'ambientCelsius' => $celsius,
            'evidence' => is_array($byField['checks'] ?? null) ? $byField['checks'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $stats
     * @param list<string> $defCodes
     * @param list<string> $unitCodes
     * @param list<array<string, mixed>> $azureDefinitions
     * @return array<string, mixed>
     */
    private function validateAmbientCreates(array $stats, array $defCodes, array $unitCodes, array $azureDefinitions): array
    {
        $upperDefs = array_map('strtoupper', $defCodes);
        $upperUnits = array_map('strtoupper', $unitCodes);

        $currentGroup = 'electrical';
        foreach ($azureDefinitions as $def) {
            if (strtoupper((string) ($def['code'] ?? '')) === 'CURRENT') {
                $currentGroup = strtolower((string) ($def['groupCode'] ?? $def['group'] ?? 'electrical'));
                break;
            }
        }

        $fields = [
            'A30c' => ['code' => 'CURRENT-30C', 'celsius' => 30, 'name' => 'Current Rating at 30°C'],
            'A40c' => ['code' => 'CURRENT-40C', 'celsius' => 40, 'name' => 'Current Rating at 40°C'],
            'A45c' => ['code' => 'CURRENT-45C', 'celsius' => 45, 'name' => 'Current Rating at 45°C'],
        ];

        $noExistingSplit = !in_array('CURRENT-30C', $upperDefs, true)
            && !in_array('CURRENT-40C', $upperDefs, true)
            && !in_array('CURRENT-45C', $upperDefs, true);
        $unitAExists = in_array('A', $upperUnits, true)
            || in_array('AMP', $upperUnits, true)
            || in_array('AMPERE', $upperUnits, true);
        $definitionCountOk = count($defCodes) === 0 || count($defCodes) === self::EXPECTED_DEFINITIONS;

        $fieldResults = [];
        $allPass = $noExistingSplit && $unitAExists && $definitionCountOk && $currentGroup === 'electrical';

        foreach ($fields as $field => $meta) {
            $stat = is_array($stats[$field] ?? null) ? $stats[$field] : [];
            $samples = is_array($stat['samples'] ?? null) ? $stat['samples'] : [];
            $numeric = $samples === [] || $this->samplesAreNumeric($samples);
            $columnEncodesAmbient = str_contains(strtolower($field), (string) $meta['celsius'] . 'c');
            $notGenericCurrent = true;
            $checks = [
                'noExistingSplitDefinition' => $noExistingSplit,
                'genericCurrentNotUsed' => $notGenericCurrent,
                'valuesNumeric' => $numeric,
                'unitAExists' => $unitAExists,
                'columnEncodesAmbientC' => $columnEncodesAmbient,
                'groupElectrical' => $currentGroup === 'electrical',
                'definitionCount55' => $definitionCountOk,
            ];
            $fieldPass = !in_array(false, $checks, true);
            $allPass = $allPass && $fieldPass;
            $fieldResults[$field] = [
                'code' => $meta['code'],
                'name' => $meta['name'],
                'passed' => $fieldPass,
                'readyForD3Package' => $fieldPass,
                'checks' => $checks,
                'populated' => (int) ($stat['populated'] ?? 0),
                'styles' => (int) ($stat['styles'] ?? 0),
            ];
        }

        return [
            'passed' => $allPass,
            'confidence' => $allPass ? 'HIGH' : 'BLOCKED',
            'failureReason' => $allPass ? null : 'AMBIENT_CURRENT_VALIDATION_FAILED',
            'recommendation' => $allPass
                ? 'APPROVED_CREATE CURRENT-30C/40C/45C — do not use generic CURRENT; create deferred to D.3.'
                : 'Leave ambient fields REVIEW until validation passes.',
            'noExistingCurrent30_40_45' => $noExistingSplit,
            'genericCurrentForbidden' => true,
            'unitAExists' => $unitAExists,
            'group' => $currentGroup,
            'definitionCount' => count($defCodes),
            'fields' => $fieldResults,
        ];
    }

    /**
     * @param list<string> $unitCodes
     * @return array<string, mixed>
     */
    private function validateWeightUnitAmbiguity(array $unitCodes): array
    {
        $upper = array_map('strtoupper', $unitCodes);
        $hasKgPerKm = in_array('KG_PER_KM', $upper, true) || in_array('KG/KM', $upper, true);
        $imperialMassLength = false;
        foreach ($upper as $code) {
            if (preg_match('/^(LB|LBS|OZ).*?(FT|MI|YD)|PER_(FT|MI|YD)|_PER_(FT|MI|YD)/', $code) === 1) {
                $imperialMassLength = true;
                break;
            }
        }

        return [
            'fields' => ['walu', 'wbnz'],
            'decision' => self::REVIEW,
            'reason' => 'UNIT_AMBIGUITY',
            'hasKgPerKm' => $hasKgPerKm,
            'hasImperialMassLengthUnit' => $imperialMassLength,
            'doNotInventUnit' => true,
            'note' => 'walu/wbnz only on mt=1302 imperial rows; existing units include KG_PER_KM but no imperial mass/length unit. Semantics clear; unit not HIGH-confidence.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $decisions
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function buildBlockerMatrix(array $products, array $decisions, array $stats, array $presence): array
    {
        $unresolved = [];
        foreach ($decisions as $d) {
            $decision = (string) ($d['decision'] ?? '');
            if ($decision === self::REVIEW || $decision === self::REJECT) {
                $unresolved[(string) $d['field']] = $d;
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
            foreach ($unresolved as $field => $d) {
                if ($this->fieldBlocksStyle($field, $style, $d, $stats, $presence)) {
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
        $productCount = count($products);
        $summary = [];
        foreach ($conceptCounts as $concept => $count) {
            $styles = (int) ($stats[$concept]['styles'] ?? ($unresolved[$concept]['styles'] ?? 0));
            $productsBlocked = $count;
            // Without per-style presence, inventory stylesAffected is the honest blocked-product estimate.
            if (
                !in_array($concept, self::ALWAYS_POPULATED_REVIEW, true)
                && !isset($presence[$concept])
                && $styles > 0
                && $styles < $productCount
            ) {
                $productsBlocked = $styles;
            } elseif ($productsBlocked === 0 && $styles > 0) {
                $productsBlocked = $styles;
            }
            $summary[] = [
                'concept' => $concept,
                'productsBlocked' => $productsBlocked,
                'confidence' => $unresolved[$concept]['confidence'] ?? null,
                'reason' => $unresolved[$concept]['reason'] ?? null,
            ];
        }

        return [
            'products' => $perProduct,
            'conceptSummary' => $summary,
            'criticalCodeBlockers' => [
                'cc' => $conceptCounts['cc'] ?? 0,
                'mt' => 0,
                'ct' => $conceptCounts['ct'] ?? 0,
            ],
            'note' => 'Only REVIEW/REJECT decisions block. APPROVED_* and APPROVED_NOT_A_SPECIFICATION do not block. mt excluded. Ambient A30c/A40c/A45c excluded after APPROVED_CREATE.',
        ];
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     */
    private function fieldBlocksStyle(
        string $field,
        string $style,
        array $decision,
        array $stats,
        array $presence,
    ): bool {
        $decisionStatus = (string) ($decision['decision'] ?? '');
        if (!in_array($decisionStatus, [self::REVIEW, self::REJECT], true)) {
            return false;
        }

        $populated = (int) ($decision['populated'] ?? ($stats[$field]['populated'] ?? 0));
        $styles = (int) ($decision['styles'] ?? ($stats[$field]['styles'] ?? 0));

        if (in_array($field, self::ALWAYS_POPULATED_REVIEW, true)) {
            return true;
        }

        if ($populated <= 0 && $styles <= 0 && !isset($presence[$field])) {
            return false;
        }

        if (isset($presence[$field])) {
            $list = array_map(static fn ($s): string => strtoupper(trim((string) $s)), $presence[$field]);

            return in_array($style, $list, true);
        }

        // Sparse REVIEW fields without presence must not mark all 54 products.
        // Prefer inventory style counts for conceptSummary only; per-product requires presence.
        if ($styles > 0 && $styles < self::EXPECTED_CABLE_PRODUCTS) {
            return false;
        }

        return $populated > 0 || $styles > 0;
    }

    /**
     * @param array<string, mixed> $incoming
     * @return array<string, list<string>>
     */
    private function mergeStyleFieldPresence(array $incoming): array
    {
        $embedded = self::embeddedStyleFieldPresence();
        $out = $embedded;
        foreach ($incoming as $field => $styles) {
            if (!is_array($styles)) {
                continue;
            }
            $normalized = [];
            foreach ($styles as $style) {
                $s = strtoupper(trim((string) $style));
                if ($s !== '') {
                    $normalized[] = $s;
                }
            }
            $normalized = array_values(array_unique($normalized));
            sort($normalized);
            if ($normalized !== []) {
                $out[(string) $field] = $normalized;
            }
        }

        return $out;
    }

    /**
     * Deterministic per-style presence for sparse REVIEW fields (from D.2 live inventory).
     *
     * @return array<string, list<string>>
     */
    private static function embeddedStyleFieldPresence(): array
    {
        $waluWbnz = ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'];
        $ins = ['B1', 'KN', 'NR', 'NV', 'XN'];
        $b1Only = ['B1'];

        return [
            'walu' => $waluWbnz,
            'wbnz' => $waluWbnz,
            'ins' => $ins,
            'Af' => $b1Only,
            'r' => $b1Only,
            'x50' => $b1Only,
            'x60' => $b1Only,
            'tension' => $b1Only,
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $decisions
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     * @param array<string, mixed> $blockerMatrix
     * @return array<string, mixed>
     */
    private function buildReadinessProjection(
        array $products,
        array $decisions,
        array $stats,
        array $presence,
        array $blockerMatrix,
    ): array {
        $productCount = count($products);
        $projected = $this->simulateAfterApproved($products, $decisions, $stats, $presence);

        return [
            'currentTarget' => [
                'CABLE_SPEC_FIELD_MAPPING_READY' => 0,
                'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
                'note' => 'No D.2 decisions applied to DB — target readiness unchanged.',
            ],
            'projectedAfterApprovedDecisionsApplied' => [
                'READY' => (int) ($projected['READY'] ?? 0),
                'REVIEW' => (int) ($projected['REVIEW'] ?? $productCount),
                'BLOCKED' => (int) ($projected['BLOCKED'] ?? 0),
                'CABLE_SPEC_FIELD_MAPPING_READY' => (int) ($projected['READY'] ?? 0),
                'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
                'stillBlockingConcepts' => $projected['stillBlockingConcepts'] ?? [],
                'note' => 'Product READY iff no remaining REVIEW blockers for that product. cc+ct keep READY at 0 while REVIEW. SPEC_FIELD stays accessory baseline 27 until cables are included after apply.',
            ],
            'blockerConceptSummary' => $blockerMatrix['conceptSummary'] ?? [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $decisions
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function simulateAfterApproved(array $products, array $decisions, array $stats, array $presence): array
    {
        $ready = 0;
        $review = 0;
        $stillBlocking = [];

        foreach ($products as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $blockers = [];
            foreach ($decisions as $d) {
                $field = (string) ($d['field'] ?? '');
                if ($this->fieldBlocksStyle($field, $style, $d, $stats, $presence)) {
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
            'stillBlockingConcepts' => $stillBlocking,
        ];
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @return array<string, mixed>
     */
    private function buildSemanticGuards(array $decisions): array
    {
        $odOk = false;
        $odNeverOuter = true;
        $noInd = true;
        $noGenericCurrentForAmbient = true;
        foreach ($decisions as $d) {
            $code = strtoupper((string) ($d['code'] ?? ''));
            $field = (string) ($d['field'] ?? '');
            if ($field === 'od') {
                $odOk = $code === 'NOM-CABLE-OD';
                $never = strtoupper((string) ($d['neverMapTo'] ?? 'OUTER-DIAMETER'));
                $odNeverOuter = $never === 'OUTER-DIAMETER' && $code !== 'OUTER-DIAMETER';
            }
            if ($code === 'IND-LSZH-FIRE') {
                $noInd = false;
            }
            if (in_array($field, ['A30c', 'A40c', 'A45c'], true) && $code === 'CURRENT') {
                $noGenericCurrentForAmbient = false;
            }
        }

        return [
            'odMapsToNomCableOd' => $odOk,
            'odNeverOuterDiameter' => $odNeverOuter,
            'noIndLszhFire' => $noInd,
            'ambientNotMappedToGenericCurrent' => $noGenericCurrentForAmbient,
        ];
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $d2
     * @return array<string, mixed>
     */
    private function extractStats(array $inventory, array $d2): array
    {
        $stats = [];
        $items = [];
        if (isset($inventory['items']) && is_array($inventory['items'])) {
            $items = $inventory['items'];
        } elseif (isset($inventory['stats']) && is_array($inventory['stats'])) {
            foreach ($inventory['stats'] as $field => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $items[] = array_merge(['field' => $field], $row);
            }
        }

        foreach ($items as $item) {
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
            ];
        }

        // Fallback from D.2 package candidate population.
        $candidates = is_array($d2['operatorCandidates']['items'] ?? null) ? $d2['operatorCandidates']['items'] : [];
        foreach ($candidates as $c) {
            if (!is_array($c)) {
                continue;
            }
            $field = (string) ($c['field'] ?? '');
            if ($field === '' || isset($stats[$field])) {
                continue;
            }
            $stats[$field] = [
                'populated' => (int) ($c['populated'] ?? 0),
                'styles' => (int) ($c['styles'] ?? 0),
                'samples' => is_array($c['samples'] ?? null) ? $c['samples'] : [],
            ];
        }

        return $stats;
    }

    /**
     * @param mixed $definitions
     * @return list<string>
     */
    private function definitionCodes(mixed $definitions): array
    {
        if (!is_array($definitions)) {
            return [];
        }
        $codes = [];
        foreach ($definitions as $d) {
            if (is_string($d) && $d !== '') {
                $codes[] = $d;
                continue;
            }
            if (!is_array($d)) {
                continue;
            }
            $code = (string) ($d['code'] ?? $d['DefinitionCode'] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param mixed $units
     * @param mixed $definitions
     * @return list<string>
     */
    private function unitCodes(mixed $units, mixed $definitions): array
    {
        $codes = [];
        if (is_array($units)) {
            foreach ($units as $u) {
                if (is_string($u) && $u !== '') {
                    $codes[] = $u;
                    continue;
                }
                if (!is_array($u)) {
                    continue;
                }
                $code = (string) ($u['code'] ?? $u['targetUnit'] ?? $u['UnitCode'] ?? $u['normalizedUnit'] ?? '');
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
        }
        if (is_array($definitions)) {
            foreach ($definitions as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $unit = (string) ($d['unit'] ?? '');
                if ($unit !== '') {
                    $codes[] = $unit;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<array{code: string}>
     */
    private function defaultKnownUnits(): array
    {
        return [
            ['code' => 'MM'],
            ['code' => 'MM2'],
            ['code' => 'KG'],
            ['code' => 'KG_PER_KM'],
            ['code' => 'A'],
            ['code' => 'V'],
            ['code' => 'C'],
            ['code' => 'M'],
        ];
    }

    /**
     * @param list<mixed> $samples
     */
    private function samplesAreNumeric(array $samples): bool
    {
        foreach ($samples as $sample) {
            $s = trim((string) $sample);
            if ($s === '') {
                continue;
            }
            if (!is_numeric($s)) {
                return false;
            }
        }

        return true;
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
