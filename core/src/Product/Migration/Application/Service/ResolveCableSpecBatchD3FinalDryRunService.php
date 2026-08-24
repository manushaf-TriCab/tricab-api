<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch D.3 — final cable spec mapping dry-run (read-only).
 *
 * Loads authoritative D.2 operator decisions and projects target mutations without writing.
 */
final class ResolveCableSpecBatchD3FinalDryRunService
{
    public const STATUS_APPROVED_MAP_EXISTING = 'APPROVED_MAP_EXISTING';
    public const STATUS_APPROVED_PENDING_DEFINITION = 'APPROVED_PENDING_DEFINITION';
    public const PACKAGE_READY = 'READY_FOR_EXPLICIT_D4_APPLY_APPROVAL';
    public const PACKAGE_BLOCKED = 'BLOCKED_NOT_READY_FOR_APPLY_APPROVAL';

    private const EXPECTED_CABLE_PRODUCTS = 54;
    private const EXPECTED_DEFINITIONS = 55;
    private const EXPECTED_UNITS = 11;
    private const EXPECTED_OPTIONS = 416;
    private const EXPECTED_STYLE_FIELD_MAPPINGS = 483;
    private const EXPECTED_FIELD_MAPPINGS = 506;
    private const ACCESSORY_SPEC_FIELD_MAPPING_READY = 27;

    private const REVIEW_FIELDS = ['cc', 'ct', 'ins', 'walu', 'wbnz', 'Af', 'r', 'x50', 'x60', 'tension'];
    private const NOT_SPEC_FIELDS = ['cable_style', 'my_row_id', 'height', 'width', 'A15c', 'A25c', 'cap', 'core_con', 'mt'];

    private const APPROVED_EXISTING_FIELDS = [
        'nc' => 'CORES',
        'xa' => 'CSA',
        'od' => 'NOM-CABLE-OD',
        'cdia' => 'NOM-COND-OD',
        'od_ins' => 'NOM-INS-OD',
        'br' => 'MIN-BEND-RADIUS',
        'wght' => 'APPROX-WEIGHT',
    ];

    private const APPROVED_CREATE_FIELDS = [
        'ne' => 'EARTH-CORES',
        'xae' => 'EARTH-CSA',
        'ode_ins' => 'EARTH-INS-OD',
        'od_bed' => 'BEDDING-OD',
        'od_arm' => 'ARMOUR-OD',
        'od_brd' => 'BRAID-OD',
        'A30c' => 'CURRENT-30C',
        'A40c' => 'CURRENT-40C',
        'A45c' => 'CURRENT-45C',
    ];

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function resolve(array $inputs): array
    {
        $d2 = is_array($inputs['d2Package'] ?? null) ? $inputs['d2Package'] : [];
        $azure = is_array($inputs['azure'] ?? null) ? $inputs['azure'] : [];
        $inventory = is_array($inputs['inventory'] ?? null) ? $inputs['inventory'] : [];
        $existingMappings = is_array($inputs['existingMappings'] ?? null) ? $inputs['existingMappings'] : [];
        $forbidden = (int) ($inputs['forbiddenWriteAttempts'] ?? 0);
        $offlineFallback = (bool) ($inputs['offlineFallbackPresence'] ?? false);
        $presence = $this->mergeStyleFieldPresence(
            is_array($inputs['styleFieldPresence'] ?? null) ? $inputs['styleFieldPresence'] : [],
            $inventory,
        );

        $cableProducts = $this->filterCableProducts($inputs['classificationReadyProducts'] ?? []);
        $cableStyles = $this->cableStyleCodes($cableProducts);
        $presence = $this->extendApprovedPresence($presence, $d2, $cableStyles, $inventory, $offlineFallback);

        $decisions = $this->loadD2Decisions($d2, $inputs);
        $defsByCode = $this->indexDefinitions($azure['definitions'] ?? []);
        $existingByKey = $this->indexExistingMappings($existingMappings);
        $stats = $this->extractStats($inventory, $d2);

        $targetBaseline = $this->buildTargetBaseline($azure);
        $approvedExistingValidation = $this->validateApprovedExistingMappings($decisions, $defsByCode, $existingByKey);
        $approvedDefinitionsValidation = $this->validateApprovedDefinitions($decisions, $defsByCode, $azure);
        $mappingRows = $this->buildProjectedMappings($decisions, $presence, $cableStyles, $defsByCode);
        $styleFieldMappingProjection = $this->classifyMappingProjection($mappingRows, $existingByKey);
        $reviewExclusions = $this->buildReviewExclusions($mappingRows);
        $valueCompatibility = $this->buildValueCompatibility($decisions, $stats);
        $semanticGuards = $this->buildSemanticGuards($decisions, $defsByCode);
        $blockerMatrix = $this->buildBlockerMatrix($cableProducts, $decisions, $stats, $presence);
        $readinessProjection = $this->buildReadinessProjection($cableProducts, $decisions, $stats, $presence, $blockerMatrix);
        $applyProjection = $this->buildApplyProjection($approvedDefinitionsValidation, $styleFieldMappingProjection);
        $idempotencyProjection = $this->buildIdempotencyProjection($approvedDefinitionsValidation, $styleFieldMappingProjection);
        $batchC2Integrity = $this->buildBatchC2Integrity($azure, $defsByCode);

        $blockers = $this->collectPackageBlockers(
            $decisions,
            $approvedExistingValidation,
            $approvedDefinitionsValidation,
            $styleFieldMappingProjection,
            $valueCompatibility,
            $semanticGuards,
            $targetBaseline,
            count($cableProducts),
        );

        $packageStatus = $blockers === [] ? self::PACKAGE_READY : self::PACKAGE_BLOCKED;

        $artifacts = [
            'cable-spec-d3-approved-definitions.json',
            'cable-spec-d3-approved-field-mappings.json',
            'cable-spec-d3-review-exclusions.json',
            'cable-spec-d3-apply-projection.json',
            'cable-spec-d3-idempotency-projection.json',
            'cable-spec-d3-readiness.json',
            'cable-spec-d3-final-package.json',
        ];

        return [
            'phase' => '12.7-batch-d3-cable-spec-final-dry-run',
            'mode' => 'FINAL_DRY_RUN_ONLY',
            'packageStatus' => $packageStatus,
            'd3Apply' => 'NOT_APPROVED',
            'd4Apply' => 'NOT_STARTED',
            'approvalBoundary' => [
                'cableSpecD3ApplyGuard' => CableSpecBatchD3CliGuard::APPLY_NOT_APPROVED,
                'definitionsCreated' => false,
                'mappingsInserted' => false,
                'specificationValuesWritten' => false,
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => $forbidden,
            ],
            'targetSafety' => [
                'definitionCreates' => 0,
                'optionCreates' => 0,
                'unitCreates' => 0,
                'fieldMappingInserts' => 0,
                'fieldMappingUpdates' => 0,
                'productSpecValueWrites' => 0,
                'variantSpecValueWrites' => 0,
                'totalWrites' => 0,
            ],
            'targetBaseline' => $targetBaseline,
            'd2DecisionSource' => [
                'loadedFromArtifacts' => true,
                'reInferred' => false,
                'approvedMapExisting' => count(self::APPROVED_EXISTING_FIELDS),
                'approvedCreate' => count(self::APPROVED_CREATE_FIELDS),
                'approvedNotSpecification' => count(self::NOT_SPEC_FIELDS),
                'review' => count(self::REVIEW_FIELDS),
            ],
            'operatorDecisions' => [
                'count' => count($decisions),
                'items' => $decisions,
            ],
            'approvedExistingMappingsValidation' => $approvedExistingValidation,
            'approvedDefinitionsValidation' => $approvedDefinitionsValidation,
            'styleFieldMappingProjection' => $styleFieldMappingProjection,
            'approvedFieldMappings' => [
                'count' => count($mappingRows),
                'items' => $mappingRows,
            ],
            'reviewExclusions' => $reviewExclusions,
            'valueCompatibility' => $valueCompatibility,
            'readinessProjection' => $readinessProjection,
            'blockerMatrix' => $blockerMatrix,
            'applyProjection' => $applyProjection,
            'idempotencyProjection' => $idempotencyProjection,
            'semanticGuards' => $semanticGuards,
            'batchC2Integrity' => $batchC2Integrity,
            'packageBlockers' => $blockers,
            'artifacts' => $artifacts,
            'recommendation' => [
                'status' => $packageStatus === self::PACKAGE_READY ? 'READY_FOR_EXPLICIT_D4_APPLY_APPROVAL' : 'BLOCKED',
                'note' => $packageStatus === self::PACKAGE_READY
                    ? 'Final dry-run complete — await explicit D.4 apply approval.'
                    : 'Resolve blockers before D.4 apply approval.',
                'doNotStartD4Apply' => true,
            ],
            'nextAction' => 'AWAIT_EXPLICIT_D4_INSTRUCTION',
            'generatedAtUtc' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $d2
     * @param array<string, mixed> $inputs
     * @return list<array<string, mixed>>
     */
    private function loadD2Decisions(array $d2, array $inputs): array
    {
        $items = [];
        if (isset($d2['operatorDecisions']['items']) && is_array($d2['operatorDecisions']['items'])) {
            $items = $d2['operatorDecisions']['items'];
        } elseif (isset($inputs['operatorDecisions']['items']) && is_array($inputs['operatorDecisions']['items'])) {
            $items = $inputs['operatorDecisions']['items'];
        } else {
            foreach (['approvedExistingMappings', 'approvedDefinitions', 'approvedNotSpecifications', 'unresolvedReview'] as $key) {
                $chunk = is_array($d2[$key] ?? null) ? $d2[$key] : (is_array($inputs[$key] ?? null) ? $inputs[$key] : []);
                foreach ($chunk as $row) {
                    if (is_array($row)) {
                        $items[] = $row;
                    }
                }
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) ($a['field'] ?? ''), (string) ($b['field'] ?? '')),
        );

        return $items;
    }

    /**
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function buildTargetBaseline(array $azure): array
    {
        $definitions = (int) ($azure['definitionCount'] ?? count(is_array($azure['definitions'] ?? null) ? $azure['definitions'] : []));
        $units = (int) ($azure['unitCount'] ?? self::EXPECTED_UNITS);
        $options = (int) ($azure['optionCount'] ?? self::EXPECTED_OPTIONS);
        $styleMaps = (int) ($azure['styleFieldMappingCount'] ?? self::EXPECTED_STYLE_FIELD_MAPPINGS);
        $totalMaps = (int) ($azure['fieldMappingCount'] ?? self::EXPECTED_FIELD_MAPPINGS);

        return [
            'server' => MigrationPayload::string($azure['server'] ?? 'tricab-mel'),
            'database' => MigrationPayload::string($azure['database'] ?? 'tc'),
            'liveValidation' => MigrationPayload::string($azure['liveValidation'] ?? 'UNKNOWN'),
            'definitions' => $definitions,
            'units' => $units,
            'options' => $options,
            'styleFieldMappings' => $styleMaps,
            'totalFieldMappings' => $totalMaps,
            'expected' => [
                'definitions' => self::EXPECTED_DEFINITIONS,
                'units' => self::EXPECTED_UNITS,
                'options' => self::EXPECTED_OPTIONS,
                'styleFieldMappings' => self::EXPECTED_STYLE_FIELD_MAPPINGS,
                'totalFieldMappings' => self::EXPECTED_FIELD_MAPPINGS,
            ],
            'driftDetected' => $definitions !== self::EXPECTED_DEFINITIONS
                || $units !== self::EXPECTED_UNITS
                || $options !== self::EXPECTED_OPTIONS
                || $styleMaps !== self::EXPECTED_STYLE_FIELD_MAPPINGS
                || $totalMaps !== self::EXPECTED_FIELD_MAPPINGS,
            'drift' => [
                'definitions' => $definitions - self::EXPECTED_DEFINITIONS,
                'units' => $units - self::EXPECTED_UNITS,
                'options' => $options - self::EXPECTED_OPTIONS,
                'styleFieldMappings' => $styleMaps - self::EXPECTED_STYLE_FIELD_MAPPINGS,
                'totalFieldMappings' => $totalMaps - self::EXPECTED_FIELD_MAPPINGS,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @param array<string, array<string, mixed>> $defsByCode
     * @param array<string, array<string, mixed>> $existingByKey
     * @return list<array<string, mixed>>
     */
    private function validateApprovedExistingMappings(array $decisions, array $defsByCode, array $existingByKey): array
    {
        $out = [];
        foreach ($decisions as $d) {
            if (($d['decision'] ?? '') !== RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_MAP_EXISTING) {
                continue;
            }
            $field = (string) ($d['field'] ?? '');
            $code = strtoupper((string) ($d['code'] ?? ''));
            $expected = self::APPROVED_EXISTING_FIELDS[$field] ?? $code;
            $validationStatus = 'VALID';
            $notes = [];

            if ($code !== ($selfExpected = self::APPROVED_EXISTING_FIELDS[$field] ?? '')) {
                $validationStatus = 'CONFLICT';
                $notes[] = 'code_mismatch_expected_' . $selfExpected;
            }
            if (!isset($defsByCode[$code])) {
                $validationStatus = 'TARGET_DEFINITION_MISSING';
            }
            if ($field === 'od' && $code !== 'NOM-CABLE-OD') {
                $validationStatus = 'CONFLICT';
                $notes[] = 'od_must_map_nom_cable_od';
            }
            if ($field === 'od' && $code === 'OUTER-DIAMETER') {
                $validationStatus = 'CONFLICT';
                $notes[] = 'od_must_not_map_outer_diameter';
            }

            $identicalCount = 0;
            foreach ($existingByKey as $existing) {
                if (
                    strtolower((string) ($existing['sourceField'] ?? '')) === strtolower($field)
                    && strtoupper((string) ($existing['targetDefinitionCode'] ?? '')) === $code
                    && ($existing['sourceTable'] ?? '') === 'cable_specs'
                ) {
                    ++$identicalCount;
                }
            }
            if ($validationStatus === 'VALID' && $identicalCount > 0) {
                $validationStatus = 'IDENTICAL';
            }

            $out[] = [
                'field' => $field,
                'targetDefinitionCode' => $code,
                'expectedDefinitionCode' => $expected,
                'validationStatus' => $validationStatus,
                'targetDefinitionExists' => isset($defsByCode[$code]),
                'targetDefinitionId' => $defsByCode[$code]['publicId'] ?? null,
                'identicalExistingMappings' => $identicalCount,
                'notes' => $notes,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @param array<string, array<string, mixed>> $defsByCode
     * @param array<string, mixed> $azure
     * @return list<array<string, mixed>>
     */
    private function validateApprovedDefinitions(array $decisions, array $defsByCode, array $azure): array
    {
        $upperDefs = array_map('strtoupper', array_keys($defsByCode));
        $ambientExists = in_array('CURRENT-30C', $upperDefs, true)
            || in_array('CURRENT-40C', $upperDefs, true)
            || in_array('CURRENT-45C', $upperDefs, true);

        $out = [];
        foreach ($decisions as $d) {
            if (($d['decision'] ?? '') !== RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_CREATE) {
                continue;
            }
            $field = (string) ($d['field'] ?? '');
            $code = strtoupper((string) ($d['code'] ?? ''));
            $status = 'valid';
            $notes = [];

            if (isset($defsByCode[$code])) {
                $status = 'already_exists';
                $notes[] = 'definition_already_live_no_create_needed';
            } elseif ($code === 'IND-LSZH-FIRE') {
                $status = 'conflict';
                $notes[] = 'ind_lszh_fire_forbidden';
            } elseif (in_array($field, ['A30c', 'A40c', 'A45c'], true)) {
                if ($ambientExists) {
                    $status = 'conflict';
                    $notes[] = 'ambient_split_definition_already_exists';
                }
                if ($code === 'CURRENT') {
                    $status = 'conflict';
                    $notes[] = 'generic_current_forbidden_for_ambient';
                }
            } elseif ($field === 'xae') {
                if (($d['dataType'] ?? '') !== 'decimal') {
                    $status = 'conflict';
                    $notes[] = 'earth_csa_must_be_decimal';
                }
            }

            $out[] = [
                'field' => $field,
                'code' => $code,
                'name' => $d['name'] ?? null,
                'dataType' => $d['dataType'] ?? null,
                'unit' => $d['unit'] ?? null,
                'group' => $d['group'] ?? null,
                'validationStatus' => $status,
                'targetDefinitionExists' => isset($defsByCode[$code]),
                'notes' => $notes,
                'neverMapTo' => $d['neverMapTo'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @param array<string, list<string>> $presence
     * @param list<string> $cableStyles
     * @param array<string, array<string, mixed>> $defsByCode
     * @return list<array<string, mixed>>
     */
    private function buildProjectedMappings(
        array $decisions,
        array $presence,
        array $cableStyles,
        array $defsByCode,
    ): array {
        $rows = [];
        foreach ($decisions as $d) {
            $decision = (string) ($d['decision'] ?? '');
            if (!in_array($decision, [
                RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_MAP_EXISTING,
                RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_CREATE,
            ], true)) {
                continue;
            }
            $field = (string) ($d['field'] ?? '');
            $code = strtoupper((string) ($d['code'] ?? ''));
            if ($field === '' || $code === '' || $code === 'IND-LSZH-FIRE') {
                continue;
            }

            $styles = $this->stylesForField($field, $d, $presence, $cableStyles);
            $status = $decision === RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_MAP_EXISTING
                ? self::STATUS_APPROVED_MAP_EXISTING
                : self::STATUS_APPROVED_PENDING_DEFINITION;

            foreach ($styles as $style) {
                $row = [
                    'styleCode' => $style,
                    'legacyField' => $field,
                    'sourceField' => $field,
                    'targetDefinitionCode' => $code,
                    'decisionSource' => 'operator D.2',
                    'status' => $status,
                    'sourceSystem' => 'tc',
                    'sourceTable' => 'cable_specs',
                ];
                if ($status === self::STATUS_APPROVED_MAP_EXISTING && isset($defsByCode[$code])) {
                    $row['targetDefinitionId'] = $defsByCode[$code]['publicId'] ?? null;
                } else {
                    $row['pendingDefinitionCode'] = $code;
                    $row['targetDefinitionId'] = null;
                }
                $rows[] = $row;
            }
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $fieldCmp = strcmp((string) $a['legacyField'], (string) $b['legacyField']);
                if ($fieldCmp !== 0) {
                    return $fieldCmp;
                }

                return strcmp((string) $a['styleCode'], (string) $b['styleCode']);
            },
        );

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $mappingRows
     * @param array<string, array<string, mixed>> $existingByKey
     * @return array<string, mixed>
     */
    private function classifyMappingProjection(array $mappingRows, array $existingByKey): array
    {
        $toInsert = 0;
        $identicalExisting = 0;
        $toUpdate = 0;
        $conflicts = 0;
        $conflictItems = [];

        foreach ($mappingRows as $row) {
            $key = $this->mappingKey(
                (string) ($row['sourceSystem'] ?? 'tc'),
                (string) ($row['sourceTable'] ?? 'cable_specs'),
                (string) ($row['legacyField'] ?? $row['sourceField'] ?? ''),
                (string) ($row['styleCode'] ?? ''),
            );
            $proposed = strtoupper((string) ($row['targetDefinitionCode'] ?? ''));
            $existing = $existingByKey[$key] ?? null;

            if ($existing === null) {
                ++$toInsert;
                continue;
            }

            $existingTarget = strtoupper((string) ($existing['targetDefinitionCode'] ?? ''));
            if ($existingTarget === $proposed) {
                ++$identicalExisting;
                continue;
            }

            ++$conflicts;
            $conflictItems[] = [
                'styleCode' => $row['styleCode'] ?? null,
                'legacyField' => $row['legacyField'] ?? null,
                'existingTargetDefinitionCode' => $existingTarget,
                'proposedTargetDefinitionCode' => $proposed,
                'classification' => 'CONFLICT',
            ];
        }

        return [
            'eligible' => count($mappingRows),
            'toInsert' => $toInsert,
            'identicalExisting' => $identicalExisting,
            'toUpdate' => $toUpdate,
            'conflicts' => $conflicts,
            'conflictItems' => $conflictItems,
            'reviewExcluded' => 0,
            'notSpecificationExcluded' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $mappingRows
     * @return array<string, mixed>
     */
    private function buildReviewExclusions(array $mappingRows): array
    {
        $reviewGenerated = 0;
        $notSpecGenerated = 0;
        foreach ($mappingRows as $row) {
            $field = (string) ($row['legacyField'] ?? '');
            if (in_array($field, self::REVIEW_FIELDS, true)) {
                ++$reviewGenerated;
            }
            if (in_array($field, self::NOT_SPEC_FIELDS, true)) {
                ++$notSpecGenerated;
            }
        }

        return [
            'reviewConceptMappingsGenerated' => $reviewGenerated,
            'notSpecMappingsGenerated' => $notSpecGenerated,
            'reviewFields' => self::REVIEW_FIELDS,
            'notSpecificationFields' => self::NOT_SPEC_FIELDS,
            'passed' => $reviewGenerated === 0 && $notSpecGenerated === 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @param array<string, mixed> $stats
     * @return list<array<string, mixed>>
     */
    private function buildValueCompatibility(array $decisions, array $stats): array
    {
        $out = [];
        foreach ($decisions as $d) {
            $decision = (string) ($d['decision'] ?? '');
            if (!in_array($decision, [
                RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_MAP_EXISTING,
                RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_CREATE,
            ], true)) {
                continue;
            }
            $field = (string) ($d['field'] ?? '');
            $code = strtoupper((string) ($d['code'] ?? ''));
            $stat = is_array($stats[$field] ?? null) ? $stats[$field] : [];
            $samples = is_array($stat['samples'] ?? null) ? $stat['samples'] : (is_array($d['samples'] ?? null) ? $d['samples'] : []);
            $populated = (int) ($stat['populated'] ?? $d['populated'] ?? 0);
            $parseSuccess = 0;
            $parseFailure = 0;
            foreach ($samples as $sample) {
                if ($this->sampleParsesForField($field, (string) $sample, (string) ($d['dataType'] ?? ''))) {
                    ++$parseSuccess;
                } else {
                    ++$parseFailure;
                }
            }
            if ($samples === [] && $populated > 0) {
                $parseSuccess = $populated;
            }

            $typeCompatible = $this->typeCompatible($field, (string) ($d['dataType'] ?? ''), $parseFailure === 0);
            $unitCompatible = $this->unitCompatible($field, $d);

            $out[] = [
                'field' => $field,
                'concept' => $code,
                'populatedValues' => $populated,
                'parseSuccess' => $parseSuccess,
                'parseFailure' => $parseFailure,
                'typeCompatible' => $typeCompatible,
                'unitCompatible' => $unitCompatible,
                'passed' => $parseFailure === 0 && $typeCompatible && $unitCompatible,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['field'], (string) $b['field']));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @param array<string, array<string, mixed>> $defsByCode
     * @return array<string, mixed>
     */
    private function buildSemanticGuards(array $decisions, array $defsByCode): array
    {
        $odOk = false;
        $odNeverOuter = true;
        $noInd = true;
        $ambientNotGeneric = true;
        $earthCsaDecimal = true;

        foreach ($decisions as $d) {
            $field = (string) ($d['field'] ?? '');
            $code = strtoupper((string) ($d['code'] ?? ''));
            if ($field === 'od') {
                $odOk = $code === 'NOM-CABLE-OD';
                $odNeverOuter = $code !== 'OUTER-DIAMETER';
            }
            if ($code === 'IND-LSZH-FIRE') {
                $noInd = false;
            }
            if (in_array($field, ['A30c', 'A40c', 'A45c'], true) && $code === 'CURRENT') {
                $ambientNotGeneric = false;
            }
            if ($field === 'xae' && ($d['dataType'] ?? '') !== 'decimal') {
                $earthCsaDecimal = false;
            }
        }

        $ambientSplitExists = isset($defsByCode['CURRENT-30C'])
            || isset($defsByCode['CURRENT-40C'])
            || isset($defsByCode['CURRENT-45C']);

        return [
            'odMapsToNomCableOd' => $odOk,
            'odNeverOuterDiameter' => $odNeverOuter,
            'noIndLszhFire' => $noInd,
            'ambientNotMappedToGenericCurrent' => $ambientNotGeneric,
            'earthCsaDecimal' => $earthCsaDecimal,
            'ambientSplitDefinitionsMustNotExistYet' => !$ambientSplitExists,
            'passed' => $odOk && $odNeverOuter && $noInd && $ambientNotGeneric && $earthCsaDecimal && !$ambientSplitExists,
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
            if (in_array($decision, [RecordCableSpecBatchD2OperatorDecisionsService::REVIEW, RecordCableSpecBatchD2OperatorDecisionsService::REJECT], true)) {
                $unresolved[(string) $d['field']] = $d;
            }
        }

        $conceptCounts = [];
        foreach (array_keys($unresolved) as $field) {
            $conceptCounts[$field] = 0;
        }

        $perProduct = [];
        foreach ($products as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $blockers = [];
            foreach ($unresolved as $field => $d) {
                if ($this->reviewFieldBlocksStyle($field, $style, $d, $stats, $presence)) {
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
            $styles = (int) ($stats[$concept]['styles'] ?? ($unresolved[$concept]['styles'] ?? 0));
            $productsBlocked = $count;
            if ($concept === 'cc' || $concept === 'ct') {
                $productsBlocked = self::EXPECTED_CABLE_PRODUCTS;
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
                'cc' => $conceptCounts['cc'] ?? self::EXPECTED_CABLE_PRODUCTS,
                'mt' => 0,
                'ct' => $conceptCounts['ct'] ?? self::EXPECTED_CABLE_PRODUCTS,
            ],
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
        $d2Projection = $this->buildD2ReadinessEcho($products, $decisions, $stats, $presence, $blockerMatrix);

        return [
            'current' => [
                'Cable READY' => 0,
                'Cable REVIEW' => count($products),
                'Cable BLOCKED' => 0,
                'CABLE_SPEC_FIELD_MAPPING_READY' => 0,
                'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
                'FULL_PRODUCT_MIGRATION_READY' => 54,
                'note' => 'Target readiness unchanged — D.3 is dry-run only.',
            ],
            'projectedAfterFutureApply' => [
                'Cable READY' => (int) ($d2Projection['projectedAfterApprovedDecisionsApplied']['READY'] ?? 0),
                'Cable REVIEW' => (int) ($d2Projection['projectedAfterApprovedDecisionsApplied']['REVIEW'] ?? count($products)),
                'Cable BLOCKED' => (int) ($d2Projection['projectedAfterApprovedDecisionsApplied']['BLOCKED'] ?? 0),
                'CABLE_SPEC_FIELD_MAPPING_READY' => (int) ($d2Projection['projectedAfterApprovedDecisionsApplied']['CABLE_SPEC_FIELD_MAPPING_READY'] ?? 0),
                'SPEC_FIELD_MAPPING_READY' => (int) ($d2Projection['projectedAfterApprovedDecisionsApplied']['SPEC_FIELD_MAPPING_READY'] ?? self::ACCESSORY_SPEC_FIELD_MAPPING_READY),
                'stillBlockingConcepts' => $d2Projection['projectedAfterApprovedDecisionsApplied']['stillBlockingConcepts'] ?? [],
            ],
            'blockerConceptSummary' => $blockerMatrix['conceptSummary'] ?? [],
            'd2ReadinessEcho' => $d2Projection,
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
    private function buildD2ReadinessEcho(
        array $products,
        array $decisions,
        array $stats,
        array $presence,
        array $blockerMatrix,
    ): array {
        $ready = 0;
        $review = 0;
        $stillBlocking = [];
        foreach ($products as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $blockers = [];
            foreach ($decisions as $d) {
                $field = (string) ($d['field'] ?? '');
                if (!in_array((string) ($d['decision'] ?? ''), [RecordCableSpecBatchD2OperatorDecisionsService::REVIEW, RecordCableSpecBatchD2OperatorDecisionsService::REJECT], true)) {
                    continue;
                }
                if ($this->reviewFieldBlocksStyle($field, $style, $d, $stats, $presence)) {
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
            'currentTarget' => [
                'CABLE_SPEC_FIELD_MAPPING_READY' => 0,
                'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
            ],
            'projectedAfterApprovedDecisionsApplied' => [
                'READY' => $ready,
                'REVIEW' => $review,
                'BLOCKED' => 0,
                'CABLE_SPEC_FIELD_MAPPING_READY' => $ready,
                'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
                'stillBlockingConcepts' => $stillBlocking,
            ],
            'blockerConceptSummary' => $blockerMatrix['conceptSummary'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $presence
     */
    private function reviewFieldBlocksStyle(
        string $field,
        string $style,
        array $decision,
        array $stats,
        array $presence,
    ): bool {
        if (in_array($field, ['cc', 'ct'], true)) {
            return true;
        }
        $populated = (int) ($decision['populated'] ?? ($stats[$field]['populated'] ?? 0));
        $styles = (int) ($decision['styles'] ?? ($stats[$field]['styles'] ?? 0));
        if ($populated <= 0 && $styles <= 0 && !isset($presence[$field])) {
            return false;
        }
        if (isset($presence[$field])) {
            return in_array($style, $presence[$field], true);
        }
        if ($styles > 0 && $styles < self::EXPECTED_CABLE_PRODUCTS) {
            return false;
        }

        return $populated > 0 || $styles > 0;
    }

    /**
     * @param list<array<string, mixed>> $approvedDefinitionsValidation
     * @param array<string, mixed> $styleFieldMappingProjection
     * @return array<string, mixed>
     */
    private function buildApplyProjection(array $approvedDefinitionsValidation, array $styleFieldMappingProjection): array
    {
        $defsCreate = 0;
        $defsExisting = 0;
        $defConflicts = 0;
        foreach ($approvedDefinitionsValidation as $row) {
            $status = (string) ($row['validationStatus'] ?? '');
            if ($status === 'valid') {
                ++$defsCreate;
            } elseif ($status === 'already_exists') {
                ++$defsExisting;
            } elseif ($status === 'conflict') {
                ++$defConflicts;
            }
        }

        return [
            'mode' => 'DRY_RUN_PROJECTION_ONLY',
            'writesWouldExecute' => false,
            'definitionsToCreate' => $defsCreate,
            'definitionsAlreadyExisting' => $defsExisting,
            'definitionConflicts' => $defConflicts,
            'mappingsToInsert' => (int) ($styleFieldMappingProjection['toInsert'] ?? 0),
            'mappingsIdenticalExisting' => (int) ($styleFieldMappingProjection['identicalExisting'] ?? 0),
            'mappingsToUpdate' => (int) ($styleFieldMappingProjection['toUpdate'] ?? 0),
            'mappingConflicts' => (int) ($styleFieldMappingProjection['conflicts'] ?? 0),
            'unitsToCreate' => 0,
            'optionsToCreate' => 0,
            'productSpecValues' => 0,
            'variantSpecValues' => 0,
            'otherProductMutations' => 0,
            'actualWrites' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $approvedDefinitionsValidation
     * @param array<string, mixed> $styleFieldMappingProjection
     * @return array<string, mixed>
     */
    private function buildIdempotencyProjection(array $approvedDefinitionsValidation, array $styleFieldMappingProjection): array
    {
        $defsIdentical = 0;
        foreach ($approvedDefinitionsValidation as $row) {
            if (($row['validationStatus'] ?? '') === 'valid') {
                ++$defsIdentical;
            }
        }

        $mappingIdentical = (int) ($styleFieldMappingProjection['eligible'] ?? 0);

        return [
            'secondRun' => [
                'definitionsToCreate' => 0,
                'mappingsToInsert' => 0,
                'mappingsToUpdate' => 0,
                'conflicts' => 0,
                'definitionsIdenticalExisting' => $defsIdentical,
                'mappingsIdenticalExisting' => $mappingIdentical,
            ],
            'passed' => (int) ($styleFieldMappingProjection['conflicts'] ?? 0) === 0
                && (int) ($styleFieldMappingProjection['toUpdate'] ?? 0) === 0,
        ];
    }

    /**
     * @param array<string, mixed> $azure
     * @param array<string, array<string, mixed>> $defsByCode
     * @return array<string, mixed>
     */
    private function buildBatchC2Integrity(array $azure, array $defsByCode): array
    {
        $definitions = count($defsByCode) ?: (int) ($azure['definitionCount'] ?? self::EXPECTED_DEFINITIONS);

        return [
            'definitions' => $definitions,
            'styleMappings' => (int) ($azure['styleFieldMappingCount'] ?? self::EXPECTED_STYLE_FIELD_MAPPINGS),
            'totalMappings' => (int) ($azure['fieldMappingCount'] ?? self::EXPECTED_FIELD_MAPPINGS),
            'definitionsModified' => 0,
            'mappingsModified' => 0,
            'specificationValuesModified' => 0,
            'driftDetected' => $definitions !== self::EXPECTED_DEFINITIONS,
        ];
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @param list<array<string, mixed>> $approvedExistingValidation
     * @param list<array<string, mixed>> $approvedDefinitionsValidation
     * @param array<string, mixed> $styleFieldMappingProjection
     * @param list<array<string, mixed>> $valueCompatibility
     * @param array<string, mixed> $semanticGuards
     * @param array<string, mixed> $targetBaseline
     * @return list<string>
     */
    private function collectPackageBlockers(
        array $decisions,
        array $approvedExistingValidation,
        array $approvedDefinitionsValidation,
        array $styleFieldMappingProjection,
        array $valueCompatibility,
        array $semanticGuards,
        array $targetBaseline,
        int $cableProductCount,
    ): array {
        $blockers = [];

        if ($cableProductCount !== self::EXPECTED_CABLE_PRODUCTS) {
            $blockers[] = 'CABLE_PRODUCT_COUNT_MISMATCH';
        }
        if (($styleFieldMappingProjection['toUpdate'] ?? 0) > 0) {
            $blockers[] = 'MAPPING_TO_UPDATE_NON_ZERO';
        }
        if (($styleFieldMappingProjection['conflicts'] ?? 0) > 0) {
            $blockers[] = 'MAPPING_TARGET_CONFLICT';
        }
        foreach ($approvedExistingValidation as $row) {
            if (in_array((string) ($row['validationStatus'] ?? ''), ['CONFLICT', 'TARGET_DEFINITION_MISSING'], true)) {
                $blockers[] = 'APPROVED_EXISTING_VALIDATION_' . strtoupper((string) $row['field']);
            }
        }
        foreach ($approvedDefinitionsValidation as $row) {
            if (($row['validationStatus'] ?? '') === 'conflict') {
                $blockers[] = 'APPROVED_DEFINITION_CONFLICT_' . strtoupper((string) ($row['code'] ?? ''));
            }
        }
        foreach ($valueCompatibility as $row) {
            if (($row['passed'] ?? true) === false) {
                $blockers[] = 'VALUE_COMPATIBILITY_' . strtoupper((string) ($row['field'] ?? ''));
            }
        }
        if (($semanticGuards['passed'] ?? false) !== true) {
            $blockers[] = 'SEMANTIC_GUARD_FAILED';
        }

        sort($blockers);

        return array_values(array_unique($blockers));
    }

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $inventory
     * @return array<string, list<string>>
     */
    private function mergeStyleFieldPresence(array $incoming, array $inventory): array
    {
        $embedded = CableSpecBatchD2InvestigationCollector::embeddedEvidence()['styleFieldPresence'];
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
     * @param array<string, list<string>> $presence
     * @param array<string, mixed> $d2
     * @param list<string> $cableStyles
     * @param array<string, mixed> $inventory
     * @return array<string, list<string>>
     */
    private function extendApprovedPresence(
        array $presence,
        array $d2,
        array $cableStyles,
        array $inventory,
        bool $offlineFallback = false,
    ): array {
        $stats = $this->extractStats($inventory, $d2);
        $decisions = $this->loadD2Decisions($d2, []);
        foreach ($decisions as $d) {
            $decision = (string) ($d['decision'] ?? '');
            if (!in_array($decision, [
                RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_MAP_EXISTING,
                RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_CREATE,
            ], true)) {
                continue;
            }
            $field = (string) ($d['field'] ?? '');
            if ($field === '' || !empty($presence[$field])) {
                continue;
            }
            $styles = (int) ($d['styles'] ?? ($stats[$field]['styles'] ?? 0));
            if ($styles === count($cableStyles) && $styles > 0) {
                $presence[$field] = $cableStyles;
                continue;
            }
            if ($offlineFallback && $styles > 0 && $styles < count($cableStyles)) {
                $presence[$field] = array_slice($cableStyles, 0, $styles);
            }
        }

        return $presence;
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, list<string>> $presence
     * @param list<string> $cableStyles
     * @return list<string>
     */
    private function stylesForField(string $field, array $decision, array $presence, array $cableStyles): array
    {
        if (isset($presence[$field]) && $presence[$field] !== []) {
            $styles = array_values(array_unique(array_map(
                static fn ($s): string => strtoupper(trim((string) $s)),
                $presence[$field],
            )));
            sort($styles);

            return $styles;
        }

        $styleCount = (int) ($decision['styles'] ?? 0);
        if ($styleCount > 0 && $styleCount === count($cableStyles)) {
            return $cableStyles;
        }

        return [];
    }

    /**
     * @param mixed $definitions
     * @return array<string, array<string, mixed>>
     */
    private function indexDefinitions(mixed $definitions): array
    {
        $out = [];
        if (!is_array($definitions)) {
            return $out;
        }
        foreach ($definitions as $def) {
            if (!is_array($def)) {
                continue;
            }
            $code = strtoupper((string) ($def['code'] ?? $def['DefinitionCode'] ?? ''));
            if ($code !== '') {
                $out[$code] = $def;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @return array<string, array<string, mixed>>
     */
    private function indexExistingMappings(array $mappings): array
    {
        $out = [];
        foreach ($mappings as $row) {
            $key = $this->mappingKey(
                (string) ($row['sourceSystem'] ?? 'tc'),
                (string) ($row['sourceTable'] ?? 'accessory_specs'),
                (string) ($row['sourceField'] ?? $row['legacyField'] ?? ''),
                (string) ($row['legacyStyleCode'] ?? $row['styleCode'] ?? ''),
            );
            $out[$key] = $row;
        }

        return $out;
    }

    private function mappingKey(string $sourceSystem, string $sourceTable, string $sourceField, string $legacyStyleCode): string
    {
        return strtolower($sourceSystem)
            . '|' . strtolower($sourceTable)
            . '|' . strtolower(trim($sourceField))
            . '|' . strtoupper(trim($legacyStyleCode));
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
     * @param list<array<string, mixed>> $products
     * @return list<string>
     */
    private function cableStyleCodes(array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $style = strtoupper(trim((string) ($p['style_code'] ?? $p['styleCode'] ?? '')));
            if ($style !== '') {
                $out[] = $style;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private function sampleParsesForField(string $field, string $sample, string $dataType): bool
    {
        $s = trim($sample);
        if ($s === '') {
            return true;
        }
        if (in_array($field, ['nc', 'ne'], true) || $dataType === 'integer') {
            return preg_match('/^-?\d+(\.0+)?$/', $s) === 1;
        }
        if ($dataType === 'decimal' || in_array($field, ['A30c', 'A40c', 'A45c', 'xae', 'od', 'cdia', 'br', 'wght'], true)) {
            return is_numeric($s);
        }

        return true;
    }

    private function typeCompatible(string $field, string $dataType, bool $samplesOk): bool
    {
        if ($field === 'xae') {
            return $dataType === 'decimal';
        }
        if (in_array($field, ['A30c', 'A40c', 'A45c'], true)) {
            return $dataType === 'decimal';
        }

        return $samplesOk;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function unitCompatible(string $field, array $decision): bool
    {
        if ($field === 'xae') {
            $unit = (string) ($decision['unit'] ?? '');

            return str_contains($unit, 'mm') || strtoupper((string) ($decision['unitCode'] ?? '')) === 'MM2';
        }
        if (in_array($field, ['A30c', 'A40c', 'A45c'], true)) {
            return strtoupper((string) ($decision['unit'] ?? '')) === 'A'
                || strtoupper((string) ($decision['unitCode'] ?? '')) === 'A';
        }

        return true;
    }
}
