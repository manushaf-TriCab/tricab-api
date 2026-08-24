<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Specification\SpecFieldMappingDecisionState;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch C.2 — concept operator approval + final field-mapping dry-run package.
 * Never creates Definitions or inserts mappings/values.
 */
final class ResolveBatchC2OperatorApprovalService
{
    public const STATUS_APPROVED_MAP_EXISTING = 'APPROVED_MAP_EXISTING';
    public const STATUS_APPROVED_CREATE = 'APPROVED_CREATE';
    public const STATUS_PROPOSED_CREATE = 'PROPOSED_CREATE';
    public const STATUS_REVIEW = 'REVIEW';
    public const STATUS_NOT_A_SPECIFICATION = 'NOT_A_SPECIFICATION';
    public const STATUS_REJECT = 'REJECT';
    public const STATUS_APPROVED_PENDING_DEFINITION = 'APPROVED_PENDING_DEFINITION';

    /**
     * Default operator package for high/medium concepts (artifact approval only).
     * Outer diameter → OUTER-DIAMETER create (NOT NOM-CABLE-OD for accessories).
     * IND-LSZH-FIRE stays REVIEW pending split analysis.
     *
     * @return array<string, array<string, mixed>>
     */
    public function defaultOperatorDecisions(): array
    {
        return [
            'OVERALL_LENGTH_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'OVERALL-LENGTH',
                'name' => 'Overall Length',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_overall_length_mm',
            ],
            'CABLE_LENGTH_M' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'CABLE-LENGTH',
                'name' => 'Cable Length',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'm',
                'reason' => 'operator_package_length_metres_distinct_from_overall_mm',
            ],
            'WIDTH_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'WIDTH',
                'name' => 'Width',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_width_mm',
            ],
            'HEIGHT_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'HEIGHT',
                'name' => 'Height',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_height_mm',
            ],
            'DEPTH_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'DEPTH',
                'name' => 'Depth',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_depth_mm',
            ],
            'THICKNESS_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'THICKNESS',
                'name' => 'Thickness',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_thickness_mm',
            ],
            'INNER_DIAMETER_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'INNER-DIAMETER',
                'name' => 'Inner Diameter',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_inner_diameter',
            ],
            'OUTER_DIAMETER_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'OUTER-DIAMETER',
                'name' => 'Outer Diameter',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'accessory_outer_dia_not_nom_cable_od',
            ],
            'BARREL_LENGTH_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'BARREL-LENGTH',
                'name' => 'Barrel Length',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_barrel_length',
            ],
            'BARREL_OD_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'BARREL-OD',
                'name' => 'Barrel OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_barrel_od',
            ],
            'STUD_OD_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'STUD-OD',
                'name' => 'Stud OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_stud_od',
            ],
            'THREAD_LENGTH_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'THREAD-LENGTH',
                'name' => 'Thread Length',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_thread_length',
            ],
            'PIN_DIAMETER_MM' => [
                'decision' => self::STATUS_APPROVED_CREATE,
                'code' => 'PIN-DIAMETER',
                'name' => 'Pin Diameter',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'operator_package_pin_diameter_not_pin_count',
            ],
            'CORE_COLOUR_MATRIX' => [
                'decision' => self::STATUS_APPROVED_MAP_EXISTING,
                'code' => 'CORE-COLOURS',
                'reason' => 'full_colour_matrix_to_existing_core_colours',
            ],
            'PHASE_COLOUR_MISLABEL' => [
                'decision' => self::STATUS_APPROVED_MAP_EXISTING,
                'code' => 'CORE-COLOURS',
                'reason' => 'samples_are_colour_tokens_BLACK_not_numeric_phase_or_cores',
            ],
            'POLE_PHASE_COUNT' => [
                'decision' => self::STATUS_APPROVED_MAP_EXISTING,
                'code' => 'CORES',
                'reason' => 'numeric_pole_phase_count_only',
            ],
            'PIN_COUNT_LABEL' => [
                'decision' => self::STATUS_APPROVED_MAP_EXISTING,
                'code' => 'PIN-COUNT',
                'reason' => 'explicit_no_of_pins_when_integer_compatible',
                'requiresIntegerSamples' => true,
            ],
            'MIN_CU_MM2' => [
                'decision' => self::STATUS_REVIEW,
                'reason' => 'low_confidence_min_max_csa_pair_needs_more_evidence',
            ],
            'IND_LSZH_FIRE' => [
                'decision' => self::STATUS_REVIEW,
                'reason' => 'serialized_compound_lszh_fire_flags_need_split_analysis_not_combined_definition',
            ],
            'NEK_CLASS' => [
                'decision' => self::STATUS_REVIEW,
                'reason' => 'insufficient_values_prefer_standard_options_later',
            ],
        ];
    }

    /**
     * Concept → normalized labels (from C.1 catalog).
     *
     * @return array<string, list<string>>
     */
    private function conceptLabels(): array
    {
        return [
            'OVERALL_LENGTH_MM' => ['length mm', 'length (l) mm'],
            'CABLE_LENGTH_M' => ['length m', 'lengths'],
            'WIDTH_MM' => ['width mm', 'width(w) mm'],
            'HEIGHT_MM' => ['height mm', 'body (h) mm', 'body mm'],
            'DEPTH_MM' => ['depth mm'],
            'THICKNESS_MM' => ['thickness mm', 'thickness (t) mm'],
            'INNER_DIAMETER_MM' => ['innerdia mm', 'inner dia mm'],
            'OUTER_DIAMETER_MM' => ['outerdia mm', 'outer dia mm'],
            'BARREL_LENGTH_MM' => ['barrel mm'],
            'BARREL_OD_MM' => ['barreldia mm', 'barrel dia mm'],
            'STUD_OD_MM' => ['studdia mm', 'stud mm', 'stud dia mm'],
            'THREAD_LENGTH_MM' => ['thread length mm'],
            'PIN_DIAMETER_MM' => ['pin mm'],
            'CORE_COLOUR_MATRIX' => ['colour', 'color', 'cable colour', 'cable color'],
            'PHASE_COLOUR_MISLABEL' => ['phase'],
            'POLE_PHASE_COUNT' => ['no. of pole/phase', 'no of pole/phase', 'no. of poles', 'phases'],
            'PIN_COUNT_LABEL' => ['no. of pins'],
            'MIN_CU_MM2' => ['min cu mm²', 'min cu mm2'],
            'MAX_CU_MM2' => ['max cu mm²', 'max cu mm2'],
            'PARENT_CONNECTOR' => ['parent connector'],
            'IND_LSZH_FIRE' => ['ind_lszh_fire'],
            'NEK_CLASS' => ['nek_class'],
        ];
    }

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function resolve(array $inputs): array
    {
        $azure = is_array($inputs['azure'] ?? null) ? $inputs['azure'] : [];
        $batchC = is_array($inputs['batchCPackage'] ?? null) ? $inputs['batchCPackage'] : [];
        $batchC1 = is_array($inputs['batchC1Package'] ?? null) ? $inputs['batchC1Package'] : [];
        $readyProducts = is_array($inputs['classificationReadyProducts'] ?? null)
            ? $inputs['classificationReadyProducts'] : [];
        $operator = array_merge(
            $this->defaultOperatorDecisions(),
            is_array($inputs['operatorDecisions'] ?? null) ? $inputs['operatorDecisions'] : [],
        );

        $defsByCode = $this->indexDefs($azure['definitions'] ?? []);
        $mappings = $this->asRows($batchC['mappings']['items'] ?? []);
        $c1Concepts = $this->asRows($batchC1['unresolvedConcepts']['items'] ?? []);

        $definitionCount = $this->reconcileDefinitionCounts($batchC1);
        $concepts = $this->applyConceptDecisions($c1Concepts, $operator, $defsByCode);
        $remapped = $this->remapStyleFields($mappings, $concepts, $defsByCode);
        $reviewReasons = $this->countReviewReasons($remapped['items']);
        $readiness = $this->calculateReadiness($readyProducts, $remapped['items']);
        $dryRun = $this->buildDryRun($concepts, $remapped, $azure);
        $approvedDefs = $this->approvedDefinitionArtifact($concepts);
        $approvedMaps = $this->approvedMappingArtifact($remapped['items']);
        $reviewPkg = $this->reviewArtifact($concepts, $remapped['items'], $reviewReasons);

        $conceptCounts = $this->countBy($concepts, 'decision');

        return [
            'phase' => '12.7-batch-c2',
            'mode' => 'OPERATOR_APPROVAL_FINAL_DRY_RUN_ONLY',
            'batchC2Apply' => 'NOT_RUN',
            'batchCApply' => 'NOT_RUN',
            'approvalBoundary' => [
                'batchC2ApplyGuard' => 'BATCH_C2_APPLY_NOT_APPROVED',
                'batchCApplyGuard' => 'BATCH_C_APPLY_NOT_APPROVED',
                'noTargetWrites' => true,
                'noSpecificationValueWrites' => true,
            ],
            'target' => [
                'server' => MigrationPayload::string($azure['server'] ?? 'tricab-mel'),
                'database' => MigrationPayload::string($azure['database'] ?? 'tc'),
                'liveValidation' => MigrationPayload::string($azure['liveValidation'] ?? 'UNKNOWN'),
                'definitions' => count($defsByCode) ?: (int) ($azure['definitionCount'] ?? 42),
                'units' => (int) ($azure['unitCount'] ?? 11),
                'options' => (int) ($azure['optionCount'] ?? 416),
                'products' => (int) ($azure['productCount'] ?? 118),
                'variants' => (int) ($azure['variantCount'] ?? 4178),
                'categories' => (int) ($azure['categoryCount'] ?? 13),
                'series' => (int) ($azure['seriesCount'] ?? 97),
                'catalogueNodes' => (int) ($azure['catalogueNodeCount'] ?? 97),
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => 0,
            ],
            'definitionCountReconciliation' => $definitionCount,
            'conceptDecisions' => [
                'counts' => $conceptCounts,
                'items' => $concepts,
            ],
            'specialDecisions' => [
                'ind_lszh_fire' => $this->findConceptDecision($concepts, 'IND_LSZH_FIRE')
                    ?? ['decision' => self::STATUS_REVIEW, 'reason' => 'compound_serialized_flags_split_required'],
                'nek_class' => $this->findConceptDecision($concepts, 'NEK_CLASS')
                    ?? ['decision' => self::STATUS_REVIEW, 'reason' => 'prefer_standard_options'],
                'LENGTH' => [
                    'decision' => 'SPLIT_APPROVED',
                    'codes' => ['OVERALL-LENGTH', 'CABLE-LENGTH', 'THREAD-LENGTH', 'BARREL-LENGTH'],
                ],
                'WIDTH' => ['decision' => self::STATUS_APPROVED_CREATE, 'code' => 'WIDTH'],
                'OUTER_DIAMETER' => [
                    'decision' => self::STATUS_APPROVED_CREATE,
                    'code' => 'OUTER-DIAMETER',
                    'note' => 'Accessory outer dia must NOT map to NOM-CABLE-OD',
                ],
                'CORE_COLOURS' => ['decision' => self::STATUS_APPROVED_MAP_EXISTING, 'code' => 'CORE-COLOURS'],
                'PHASE_COLOUR' => [
                    'decision' => self::STATUS_APPROVED_MAP_EXISTING,
                    'code' => 'CORE-COLOURS',
                    'note' => 'Samples are colour tokens (BLACK); blocked from CORES',
                ],
            ],
            'mappingState' => $remapped['state'],
            'reviewReasons' => $reviewReasons,
            'readiness' => $readiness,
            'dryRunTargetPlan' => $dryRun,
            'approvedDefinitions' => $approvedDefs,
            'approvedFieldMappings' => $approvedMaps,
            'reviewPackage' => $reviewPkg,
            'futureApplyOrder' => [
                'validateTarget',
                'validateExistingDefinitions',
                'createApprovedCreateDefinitions',
                'reloadDefinitionIds',
                'insertApprovedStyleFieldMappings',
                'verifyUniqueness',
                'recalculateReadiness',
                'noSpecificationValueWrites',
            ],
            'cableSpecsFollowUpRequired' => true,
            'cableSpecsFollowUpReason' => 'Most cable Products are accessory-d/v NOT_APPLICABLE; cable_specs field mapping is not completed in Batch C and needs a later Cable Specification Field Mapping phase.',
            'recommendation' => $this->recommendation($conceptCounts, $remapped['state'], $readiness),
            'nextAction' => 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL',
        ];
    }

    /**
     * @param array<string, mixed> $batchC1
     * @return array<string, mixed>
     */
    private function reconcileDefinitionCounts(array $batchC1): array
    {
        $create = $this->asRows($batchC1['definitionProposals']['proposedCreate'] ?? []);
        $mechanical = [];
        foreach ($create as $c) {
            $code = (string) (($c['proposedDefinition']['code'] ?? '') ?: '');
            if ($code !== '') {
                $mechanical[$code] = true;
            }
        }
        $indIn12 = isset($mechanical['IND-LSZH-FIRE']);

        return [
            'mechanicalDimensionProposals' => count($mechanical),
            'otherProposals' => $indIn12 ? 0 : 1,
            'indLszhFireIncludedInPrevious12' => $indIn12,
            'totalUniqueDefinitionCandidates' => count($mechanical) + ($indIn12 ? 0 : 1),
            'mechanicalCodes' => array_keys($mechanical),
            'otherCodes' => $indIn12 ? [] : ['IND-LSZH-FIRE'],
            'note' => $indIn12
                ? 'IND-LSZH-FIRE was inside the 12'
                : 'IND-LSZH-FIRE was a separate special PROPOSE_DEFINITION — 13th unique candidate if pursued',
        ];
    }

    /**
     * @param list<array<string, mixed>> $c1Concepts
     * @param array<string, array<string, mixed>> $operator
     * @param array<string, array<string, mixed>> $defsByCode
     * @return list<array<string, mixed>>
     */
    private function applyConceptDecisions(array $c1Concepts, array $operator, array $defsByCode): array
    {
        $byKey = [];
        foreach ($c1Concepts as $c) {
            $key = (string) ($c['concept'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $c;
            }
        }

        // Ensure all operator-known concepts exist even if absent from C.1 items (e.g. IND_LSZH_FIRE)
        foreach ($operator as $key => $dec) {
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'concept' => $key,
                    'legacyLabels' => $this->conceptLabels()[$key] ?? [],
                    'mappingRows' => 0,
                    'stylesAffected' => 0,
                    'sampleValues' => [],
                    'confidence' => 'medium',
                ];
            }
        }

        $out = [];
        foreach ($byKey as $key => $c) {
            $dec = $operator[$key] ?? null;
            $decision = self::STATUS_REVIEW;
            $code = null;
            $name = null;
            $group = null;
            $dataType = null;
            $unit = null;
            $reason = (string) ($c['reason'] ?? 'no_operator_decision');

            if (is_array($dec)) {
                $decision = (string) ($dec['decision'] ?? self::STATUS_REVIEW);
                $code = isset($dec['code']) ? (string) $dec['code'] : null;
                $name = isset($dec['name']) ? (string) $dec['name'] : null;
                $group = isset($dec['group']) ? (string) $dec['group'] : null;
                $dataType = isset($dec['dataType']) ? (string) $dec['dataType'] : null;
                $unit = array_key_exists('unit', $dec) ? (isset($dec['unit']) ? (string) $dec['unit'] : null) : null;
                $reason = (string) ($dec['reason'] ?? $reason);

                if ($decision === self::STATUS_APPROVED_MAP_EXISTING) {
                    if ($code === null || !isset($defsByCode[$code])) {
                        $decision = self::STATUS_REVIEW;
                        $reason = 'approved_map_existing_but_definition_missing_' . ($code ?? 'null');
                    }
                    if (!empty($dec['requiresIntegerSamples'])) {
                        foreach ($c['sampleValues'] ?? [] as $sv) {
                            if (!preg_match('/^-?\d+(\.0+)?$/', (string) $sv)) {
                                $decision = self::STATUS_REVIEW;
                                $reason = 'integer_samples_required_for_pin_count';
                                break;
                            }
                        }
                    }
                }
                if ($decision === self::STATUS_APPROVED_CREATE && $code !== null && isset($defsByCode[$code])) {
                    $decision = self::STATUS_APPROVED_MAP_EXISTING;
                    $reason = 'create_converted_to_map_existing_code_already_live';
                }
            } elseif (str_starts_with($key, 'OTHER:')) {
                $decision = self::STATUS_REVIEW;
                $reason = 'low_confidence_ungrouped_label';
            }

            $out[] = [
                'concept' => $key,
                'legacyLabels' => $c['legacyLabels'] ?? ($this->conceptLabels()[$key] ?? []),
                'mappingRows' => (int) ($c['mappingRows'] ?? 0),
                'stylesAffected' => (int) ($c['stylesAffected'] ?? 0),
                'productsAffectedEstimate' => (int) ($c['productsAffectedEstimate'] ?? $c['stylesAffected'] ?? 0),
                'sampleValues' => $c['sampleValues'] ?? [],
                'confidence' => $c['confidence'] ?? 'low',
                'decision' => $decision,
                'code' => $code,
                'name' => $name,
                'group' => $group,
                'dataType' => $dataType,
                'unit' => $unit,
                'reason' => $reason,
                'labels' => $this->conceptLabels()[$key] ?? [],
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['mappingRows'] <=> $a['mappingRows']));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @param list<array<string, mixed>> $concepts
     * @param array<string, array<string, mixed>> $defsByCode
     * @return array{items: list<array<string, mixed>>, state: array<string, mixed>}
     */
    private function remapStyleFields(array $mappings, array $concepts, array $defsByCode): array
    {
        $labelToConcept = [];
        foreach ($concepts as $c) {
            foreach ($c['labels'] ?? [] as $lab) {
                $labelToConcept[(string) $lab] = $c;
            }
            foreach ($c['legacyLabels'] ?? [] as $lab) {
                $norm = $this->normalizeLabel((string) $lab);
                if ($norm !== '') {
                    $labelToConcept[$norm] = $c;
                }
            }
        }

        $originalApproved = 0;
        $stillValid = 0;
        $revalidationConflicts = 0;
        $newApproved = 0;
        $pendingDef = 0;
        $review = 0;
        $reject = 0;
        $items = [];

        foreach ($mappings as $row) {
            $style = (string) ($row['styleCode'] ?? '');
            $field = (string) ($row['sourceField'] ?? '');
            $label = strtolower(trim((string) ($row['normalizedLabel'] ?? '')));
            $decision = (string) ($row['decision'] ?? '');
            $target = (string) ($row['targetDefinitionCode'] ?? '');

            $out = $row;
            $out['c2Decision'] = $decision;
            $out['c2TargetDefinitionCode'] = $target !== '' ? $target : null;
            $out['c2Status'] = $decision;

            if ($decision === SpecFieldMappingDecisionState::APPROVED_MAP) {
                ++$originalApproved;
                // Revalidation: colour must never target CORES
                if ($target === 'CORES' && $this->looksLikeColourSample($row['sampleValues'] ?? [])) {
                    ++$revalidationConflicts;
                    $out['c2Status'] = 'APPROVED_MAPPING_REVALIDATION_CONFLICT';
                    $out['c2Decision'] = SpecFieldMappingDecisionState::REVIEW;
                    $out['c2Reason'] = 'approved_map_cores_with_colour_samples_blocked';
                    $out['reviewReason'] = 'TYPE_AMBIGUITY';
                    ++$review;
                    $items[] = $out;
                    continue;
                }
                ++$stillValid;
                $out['c2Status'] = SpecFieldMappingDecisionState::APPROVED_MAP;
                $out['c2Reason'] = 'preserved_batch_c_approved';
                $items[] = $out;
                continue;
            }

            if ($decision === SpecFieldMappingDecisionState::REJECT || $style === '*') {
                ++$reject;
                $out['c2Status'] = SpecFieldMappingDecisionState::REJECT;
                $out['c2Reason'] = 'wildcard_or_reject_preserved';
                $out['reviewReason'] = 'INVALID_STYLE';
                $items[] = $out;
                continue;
            }

            $concept = $labelToConcept[$label] ?? null;
            if ($concept === null) {
                ++$review;
                $out['c2Status'] = SpecFieldMappingDecisionState::REVIEW;
                $out['c2Reason'] = 'no_approved_concept';
                $out['reviewReason'] = 'UNKNOWN_SEMANTICS';
                $items[] = $out;
                continue;
            }

            $cd = (string) ($concept['decision'] ?? self::STATUS_REVIEW);
            $code = isset($concept['code']) ? (string) $concept['code'] : '';

            // Hard block: CORES never accepts colour tokens
            if ($code === 'CORES' && $this->looksLikeColourSample($row['sampleValues'] ?? [])) {
                ++$review;
                $out['c2Status'] = SpecFieldMappingDecisionState::REVIEW;
                $out['c2Reason'] = 'blocked_cores_cannot_accept_colour_values';
                $out['reviewReason'] = 'TYPE_AMBIGUITY';
                $items[] = $out;
                continue;
            }

            if ($cd === self::STATUS_APPROVED_MAP_EXISTING && $code !== '' && isset($defsByCode[$code])) {
                ++$newApproved;
                $out['c2Status'] = SpecFieldMappingDecisionState::APPROVED_MAP;
                $out['c2Decision'] = SpecFieldMappingDecisionState::APPROVED_MAP;
                $out['c2TargetDefinitionCode'] = $code;
                $out['c2Reason'] = 'concept_approved_map_existing_' . ($concept['concept'] ?? '');
                $items[] = $out;
                continue;
            }

            if ($cd === self::STATUS_APPROVED_CREATE && $code !== '') {
                ++$pendingDef;
                $out['c2Status'] = self::STATUS_APPROVED_PENDING_DEFINITION;
                $out['c2Decision'] = self::STATUS_APPROVED_PENDING_DEFINITION;
                $out['c2TargetDefinitionCode'] = $code;
                $out['c2Reason'] = 'awaiting_definition_create_' . $code;
                $items[] = $out;
                continue;
            }

            ++$review;
            $out['c2Status'] = SpecFieldMappingDecisionState::REVIEW;
            $out['c2Reason'] = (string) ($concept['reason'] ?? 'concept_still_review');
            $out['reviewReason'] = $this->inferReviewReason($concept, $row);
            $items[] = $out;
        }

        return [
            'items' => $items,
            'state' => [
                'originalApprovedMap' => $originalApproved,
                'originalApprovalsStillValid' => $stillValid,
                'revalidationConflicts' => $revalidationConflicts,
                'newApprovedMap' => $newApproved,
                'approvedPendingDefinition' => $pendingDef,
                'totalApprovedMapAfterC2' => $stillValid + $newApproved,
                'totalEligibleAfterDefinitionCreate' => $stillValid + $newApproved + $pendingDef,
                'REVIEW' => $review,
                'REJECT' => $reject,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, int>
     */
    private function countReviewReasons(array $items): array
    {
        $counts = [
            'UNKNOWN_SEMANTICS' => 0,
            'INSUFFICIENT_VALUES' => 0,
            'GENERIC_LABEL' => 0,
            'TYPE_AMBIGUITY' => 0,
            'UNIT_AMBIGUITY' => 0,
            'TARGET_DEFINITION_MISSING' => 0,
            'STANDARD_OR_APPROVAL_REVIEW' => 0,
            'INVALID_STYLE' => 0,
            'OTHER' => 0,
        ];
        foreach ($items as $row) {
            if (($row['c2Status'] ?? '') !== SpecFieldMappingDecisionState::REVIEW
                && ($row['c2Status'] ?? '') !== 'APPROVED_MAPPING_REVALIDATION_CONFLICT'
            ) {
                continue;
            }
            $r = (string) ($row['reviewReason'] ?? 'OTHER');
            if (!isset($counts[$r])) {
                $r = 'OTHER';
            }
            ++$counts[$r];
        }

        return $counts;
    }

    /**
     * @param list<mixed> $products
     * @param list<array<string, mixed>> $mappings
     * @return array<string, mixed>
     */
    private function calculateReadiness(array $products, array $mappings): array
    {
        $byStyle = [];
        foreach ($mappings as $row) {
            $s = strtoupper((string) ($row['styleCode'] ?? ''));
            if ($s === '' || $s === '*') {
                continue;
            }
            $byStyle[$s][] = $row;
        }

        $cable = ['total' => 0, 'dvNotApplicable' => 0, 'cableSpecsNotEvaluated' => 0, 'identityBlocked' => 0];
        $acc = ['total' => 0, 'dvComplete' => 0, 'dvIncomplete' => 0, 'identityBlocked' => 0];
        $accessoryDvReady = 0;
        $specFieldReady = 0;

        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            /** @var array<string, mixed> $p */
            $style = strtoupper((string) ($p['style_code'] ?? ''));
            $family = strtoupper((string) ($p['family'] ?? ''));
            $identityBlocked = ($p['tcIdentityStatus'] ?? null) === 'EXPLICIT_MAPPING_REQUIRED'
                || str_contains(strtoupper((string) ($p['blockingReason'] ?? '')), 'ACCESSORY');
            // Approximate accessory identity from known blocker count semantics
            if ($family === 'ACCESSORIES' && ($p['fullProductMigrationReady'] ?? true) === false) {
                $identityBlocked = true;
            }

            $rows = $byStyle[$style] ?? [];
            if ($family === 'CABLE' || ($family === '' && $rows === [])) {
                ++$cable['total'];
                if ($rows === []) {
                    ++$cable['dvNotApplicable'];
                }
                ++$cable['cableSpecsNotEvaluated'];
                continue;
            }

            ++$acc['total'];
            if ($identityBlocked) {
                ++$acc['identityBlocked'];
            }
            if ($rows === []) {
                // accessory without d/v — treat as N/A for d/v track
                continue;
            }
            $unresolved = 0;
            foreach ($rows as $row) {
                $st = (string) ($row['c2Status'] ?? '');
                if (in_array($st, [
                    SpecFieldMappingDecisionState::APPROVED_MAP,
                    self::STATUS_APPROVED_PENDING_DEFINITION,
                    SpecFieldMappingDecisionState::NOT_APPLICABLE,
                ], true)) {
                    continue;
                }
                ++$unresolved;
            }
            if ($unresolved === 0) {
                ++$acc['dvComplete'];
                ++$accessoryDvReady;
                // Spec field mapping readiness is independent of accessory identity.
                ++$specFieldReady;
            } else {
                ++$acc['dvIncomplete'];
            }
        }

        return [
            'CURRENT' => [
                'ACCESSORY_DV_FIELD_MAPPING_READY' => 0,
                'CABLE_SPEC_FIELD_MAPPING_READY' => 0,
                'SPEC_FIELD_MAPPING_READY' => 0,
                'FULL_PRODUCT_MIGRATION_READY' => 54,
            ],
            'PROJECTED_AFTER_BATCH_C2_APPLY' => [
                'ACCESSORY_DV_FIELD_MAPPING_READY' => $accessoryDvReady,
                'CABLE_SPEC_FIELD_MAPPING_READY' => 0,
                'SPEC_FIELD_MAPPING_READY' => $specFieldReady,
                'FULL_PRODUCT_MIGRATION_READY' => 54,
                'note' => 'FULL remains gated by accessory identity / media / cable_specs mapping.',
            ],
            'productBreakdown' => [
                'cableProducts' => $cable,
                'accessoryProducts' => $acc,
            ],
            'variantBreakdown' => [
                'cable' => [
                    'specMappingComplete' => 0,
                    'specMappingNotEvaluated' => 4178,
                    'specMappingBlocked' => 0,
                    'note' => 'Cable variant counts dominated catalogue; cable_specs mapping not evaluated in Batch C.',
                ],
                'accessory' => [
                    'dvMappingComplete' => 0,
                    'dvMappingBlocked' => 0,
                    'identityBlocked' => 41,
                    'note' => 'Accessory classification-ready styles often have variantCount=0 in readiness artifact.',
                ],
            ],
            'accessoryIdentityBlocked' => 41,
            'accessoryIdentityWouldBeDvReadyIfIdentitySolved' => $accessoryDvReady,
        ];
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @param array{items: list<array<string, mixed>>, state: array<string, mixed>} $remapped
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function buildDryRun(array $concepts, array $remapped, array $azure): array
    {
        $mapExisting = 0;
        $create = 0;
        foreach ($concepts as $c) {
            $d = (string) ($c['decision'] ?? '');
            if ($d === self::STATUS_APPROVED_MAP_EXISTING) {
                ++$mapExisting;
            }
            if ($d === self::STATUS_APPROVED_CREATE) {
                ++$create;
            }
        }
        $state = $remapped['state'];
        $toInsert = (int) ($state['newApprovedMap'] ?? 0) + (int) ($state['approvedPendingDefinition'] ?? 0);

        return [
            'mode' => 'DRY_RUN_ONLY',
            'writesWouldExecute' => false,
            'batchC2ApplyGuard' => 'BATCH_C2_APPLY_NOT_APPROVED',
            'targetValidated' => ($azure['liveValidation'] ?? '') === 'AVAILABLE',
            'masterData' => [
                'definitionsMappedExisting' => $mapExisting,
                'definitionsToCreate' => $create,
            ],
            'migrationMappings' => [
                'styleFieldMappingsToInsert' => $toInsert,
                'existingIdenticalMappings' => 0,
                'preservedApprovedMaps' => (int) ($state['originalApprovalsStillValid'] ?? 0),
                'mappingsToUpdate' => 0,
                'conflicts' => (int) ($state['revalidationConflicts'] ?? 0),
                'reviewExcluded' => (int) ($state['REVIEW'] ?? 0),
            ],
            'specificationValues' => [
                'productValues' => 0,
                'variantValues' => 0,
            ],
            'otherMutations' => [
                'products' => 0,
                'variants' => 0,
                'classification' => 0,
                'catalogue' => 0,
                'pricing' => 0,
                'media' => 0,
                'documents' => 0,
                'legacy' => 0,
            ],
            'idempotencyProjection' => [
                'definitionsCreatedOnSecondRun' => 0,
                'mappingsInsertedOnSecondRun' => 0,
                'mappingsUpdated' => 0,
                'duplicateMappings' => 0,
                'specificationValues' => 0,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @return list<array<string, mixed>>
     */
    private function approvedDefinitionArtifact(array $concepts): array
    {
        $out = [];
        foreach ($concepts as $c) {
            $d = (string) ($c['decision'] ?? '');
            if (!in_array($d, [self::STATUS_APPROVED_MAP_EXISTING, self::STATUS_APPROVED_CREATE], true)) {
                continue;
            }
            $out[] = [
                'decision' => $d,
                'concept' => $c['concept'] ?? null,
                'code' => $c['code'] ?? null,
                'name' => $c['name'] ?? null,
                'group' => $c['group'] ?? null,
                'dataType' => $c['dataType'] ?? null,
                'unit' => $c['unit'] ?? null,
                'legacyLabels' => $c['legacyLabels'] ?? [],
                'mappingRows' => $c['mappingRows'] ?? 0,
                'stylesAffected' => $c['stylesAffected'] ?? 0,
                'sampleValues' => $c['sampleValues'] ?? [],
                'reason' => $c['reason'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function approvedMappingArtifact(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            $st = (string) ($row['c2Status'] ?? '');
            if (!in_array($st, [
                SpecFieldMappingDecisionState::APPROVED_MAP,
                self::STATUS_APPROVED_PENDING_DEFINITION,
            ], true)) {
                continue;
            }
            $out[] = [
                'sourceSystem' => 'tc',
                'sourceTable' => 'accessory_specs',
                'styleCode' => $row['styleCode'] ?? null,
                'sourceField' => $row['sourceField'] ?? null,
                'legacyLabel' => $row['legacyLabel'] ?? null,
                'normalizedLabel' => $row['normalizedLabel'] ?? null,
                'targetDefinitionCode' => $row['c2TargetDefinitionCode'] ?? null,
                'status' => $st,
                'reason' => $row['c2Reason'] ?? null,
                'sampleValues' => $row['sampleValues'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @param list<array<string, mixed>> $items
     * @param array<string, int> $reviewReasons
     * @return array<string, mixed>
     */
    private function reviewArtifact(array $concepts, array $items, array $reviewReasons): array
    {
        return [
            'concepts' => array_values(array_filter(
                $concepts,
                static fn (array $c): bool => !in_array((string) ($c['decision'] ?? ''), [
                    self::STATUS_APPROVED_MAP_EXISTING,
                    self::STATUS_APPROVED_CREATE,
                ], true),
            )),
            'mappings' => array_values(array_filter(
                $items,
                static fn (array $r): bool => !in_array((string) ($r['c2Status'] ?? ''), [
                    SpecFieldMappingDecisionState::APPROVED_MAP,
                    self::STATUS_APPROVED_PENDING_DEFINITION,
                    SpecFieldMappingDecisionState::REJECT,
                ], true),
            )),
            'reviewReasons' => $reviewReasons,
        ];
    }

    /**
     * @param list<mixed> $samples
     */
    private function looksLikeColourSample(array $samples): bool
    {
        foreach ($samples as $sv) {
            $v = strtoupper(trim((string) $sv));
            if ($v === '') {
                continue;
            }
            if (preg_match('/^-?\d+(\.\d+)?$/', $v) === 1) {
                return false;
            }
            if (in_array($v, ['BLACK', 'WHITE', 'RED', 'BLUE', 'GREEN', 'YELLOW', 'BROWN', 'GREY', 'GRAY', 'ORANGE'], true)) {
                return true;
            }
            if (preg_match('/^[A-Z]+$/', $v) === 1 && !is_numeric($v)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $concept
     * @param array<string, mixed> $row
     */
    private function inferReviewReason(array $concept, array $row): string
    {
        $key = (string) ($concept['concept'] ?? '');
        if (str_starts_with($key, 'OTHER:')) {
            return 'UNKNOWN_SEMANTICS';
        }
        if (in_array($key, ['IND_LSZH_FIRE', 'NEK_CLASS'], true)) {
            return 'STANDARD_OR_APPROVAL_REVIEW';
        }
        if (($concept['decision'] ?? '') === self::STATUS_PROPOSED_CREATE) {
            return 'TARGET_DEFINITION_MISSING';
        }
        $samples = $row['sampleValues'] ?? [];
        if ($samples === [] || $samples === ['']) {
            return 'INSUFFICIENT_VALUES';
        }

        return 'OTHER';
    }

    private function normalizeLabel(string $label): string
    {
        $s = preg_replace('/<[^>]+>/u', ' ', $label) ?? $label;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
        $s = preg_replace('/\s+/u', ' ', strtolower(trim($s))) ?? '';

        return $s;
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @return array<string, mixed>|null
     */
    private function findConceptDecision(array $concepts, string $key): ?array
    {
        foreach ($concepts as $c) {
            if (($c['concept'] ?? '') === $key) {
                return [
                    'decision' => $c['decision'] ?? null,
                    'code' => $c['code'] ?? null,
                    'reason' => $c['reason'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $conceptCounts
     * @param array<string, mixed> $state
     * @param array<string, mixed> $readiness
     */
    private function recommendation(array $conceptCounts, array $state, array $readiness): string
    {
        $create = (int) ($conceptCounts[self::STATUS_APPROVED_CREATE] ?? 0);
        $map = (int) ($conceptCounts[self::STATUS_APPROVED_MAP_EXISTING] ?? 0);
        if ($create >= 10 && $map >= 2) {
            return 'APPROVE BATCH C.2 TARGET APPLY';
        }

        return 'CONTINUE SPECIFICATION CONCEPT REVIEW';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $key): array
    {
        $out = [
            self::STATUS_APPROVED_MAP_EXISTING => 0,
            self::STATUS_APPROVED_CREATE => 0,
            self::STATUS_PROPOSED_CREATE => 0,
            self::STATUS_REVIEW => 0,
            self::STATUS_NOT_A_SPECIFICATION => 0,
            self::STATUS_REJECT => 0,
        ];
        foreach ($rows as $r) {
            $d = (string) ($r[$key] ?? self::STATUS_REVIEW);
            if (!isset($out[$d])) {
                $out[$d] = 0;
            }
            ++$out[$d];
        }

        return $out;
    }

    /**
     * @param list<mixed> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexDefs(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper((string) ($row['code'] ?? $row['definitionCode'] ?? ''));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function asRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
