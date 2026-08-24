<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Specification\SpecFieldMappingDecisionState;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch C.1 — unresolved concept discovery + dry-run proposals.
 * Never creates Definitions/Options/Units or inserts field mappings / values.
 */
final class ResolveBatchC1UnresolvedConceptsService
{
    /**
     * Evidence-driven concept catalog. MAP_EXISTING preferred over CREATE.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function conceptCatalog(): array
    {
        return [
        'OVERALL_LENGTH_MM' => [
            'labels' => ['length mm', 'length (l) mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'LENGTH',
            'name' => 'Length',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'high',
            'reason' => 'accessory_overall_length_mm_consistent_decimal_values',
            'existingInsufficient' => 'No approved LENGTH Definition; Batch B deferred LENGTH as too generic — accessory d/v evidence supports a single overall length in mm.',
        ],
        'CABLE_LENGTH_M' => [
            'labels' => ['length m', 'lengths'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'LENGTH-M',
            'name' => 'Length (m)',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'm',
            'confidence' => 'medium',
            'reason' => 'length_in_metres_distinct_from_mm_overall_length',
            'existingInsufficient' => 'Unit is metres, not mm; cannot share LENGTH(mm).',
        ],
        'WIDTH_MM' => [
            'labels' => ['width mm', 'width(w) mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'WIDTH',
            'name' => 'Width',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'high',
            'reason' => 'accessory_width_mm_consistent',
            'existingInsufficient' => 'No WIDTH Definition after Batch B deferral.',
        ],
        'HEIGHT_MM' => [
            'labels' => ['height mm', 'body (h) mm', 'body mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'HEIGHT',
            'name' => 'Height',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'high',
            'reason' => 'accessory_height_body_mm',
            'existingInsufficient' => 'No HEIGHT Definition.',
        ],
        'DEPTH_MM' => [
            'labels' => ['depth mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'DEPTH',
            'name' => 'Depth',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'medium',
            'reason' => 'accessory_depth_mm',
            'existingInsufficient' => 'Distinct from WIDTH/HEIGHT/LENGTH.',
        ],
        'THICKNESS_MM' => [
            'labels' => ['thickness mm', 'thickness (t) mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'THICKNESS',
            'name' => 'Thickness',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'medium',
            'reason' => 'accessory_thickness_mm',
            'existingInsufficient' => 'Distinct physical dimension.',
        ],
        'THREAD_LENGTH_MM' => [
            'labels' => ['thread length mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'THREAD-LENGTH',
            'name' => 'Thread Length',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'high',
            'reason' => 'thread_length_distinct_from_thread_od_and_overall_length',
            'existingInsufficient' => 'THREAD-OD exists; length along thread is separate.',
        ],
        'BARREL_LENGTH_MM' => [
            'labels' => ['barrel mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'BARREL-LENGTH',
            'name' => 'Barrel Length',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'high',
            'reason' => 'barrel_mm_used_as_length_dimension_not_diameter',
            'existingInsufficient' => 'Separate from BARREL-OD (barreldia).',
        ],
        'BARREL_OD_MM' => [
            'labels' => ['barreldia mm', 'barrel dia mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'BARREL-OD',
            'name' => 'Barrel OD',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'high',
            'reason' => 'barrel_diameter_label_explicit',
            'existingInsufficient' => 'Not cable OD; accessory barrel diameter.',
        ],
        'STUD_OD_MM' => [
            'labels' => ['studdia mm', 'stud mm', 'stud dia mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'STUD-OD',
            'name' => 'Stud OD',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'high',
            'reason' => 'stud_diameter_accessory_dimension',
            'existingInsufficient' => 'Not THREAD-OD; stud size is distinct.',
        ],
        'INNER_DIAMETER_MM' => [
            'labels' => ['innerdia mm', 'inner dia mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'INNER-DIAMETER',
            'name' => 'Inner Diameter',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'high',
            'reason' => 'inner_bore_diameter',
            'existingInsufficient' => 'No inner diameter Definition; NOM-COND-OD is conductor OD.',
        ],
        'OUTER_DIAMETER_MM' => [
            'labels' => ['outerdia mm', 'outer dia mm'],
            'decision' => 'MAP_EXISTING',
            'code' => 'NOM-CABLE-OD',
            'name' => 'Nom. Cable',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'OD mm',
            'confidence' => 'medium',
            'reason' => 'outer_diameter_mm_maps_to_existing_nominal_od_when_values_are_decimal_mm',
            'existingInsufficient' => null,
            'mapNote' => 'For cable-like OD use NOM-CABLE-OD; accessory-only outer dia may later split to OUTER-DIAMETER if operator rejects reuse.',
        ],
        'CORE_COLOUR_MATRIX' => [
            'labels' => ['colour', 'color', 'cable colour', 'cable color'],
            'decision' => 'MAP_EXISTING',
            'code' => 'CORE-COLOURS',
            'name' => 'Core Colours',
            'group' => 'construction',
            'dataType' => 'long-text',
            'unit' => null,
            'confidence' => 'high',
            'reason' => 'preserve_full_colour_matrix_not_coding_colour',
            'existingInsufficient' => null,
        ],
        'PHASE_COLOUR_MISLABEL' => [
            'labels' => ['phase'],
            'decision' => 'MAP_EXISTING',
            'code' => 'CORE-COLOURS',
            'name' => 'Core Colours',
            'group' => 'construction',
            'dataType' => 'long-text',
            'unit' => null,
            'confidence' => 'high',
            'reason' => 'type_conflict_phase_values_are_colour_names_not_integer_cores',
            'existingInsufficient' => null,
        ],
        'POLE_PHASE_COUNT' => [
            'labels' => ['no. of pole/phase', 'no of pole/phase', 'no. of poles', 'phases'],
            'decision' => 'MAP_EXISTING',
            'code' => 'CORES',
            'name' => 'Number of cores',
            'group' => 'construction',
            'dataType' => 'integer',
            'unit' => null,
            'confidence' => 'medium',
            'reason' => 'numeric_pole_phase_count_compatible_with_CORES',
            'existingInsufficient' => null,
        ],
        'PIN_COUNT_LABEL' => [
            'labels' => ['no. of pins'],
            'decision' => 'MAP_EXISTING',
            'code' => 'PIN-COUNT',
            'name' => 'Pins',
            'group' => 'construction',
            'dataType' => 'integer',
            'unit' => null,
            'confidence' => 'medium',
            'reason' => 'explicit_pin_count_label',
            'existingInsufficient' => null,
            'requiresIntegerSamples' => true,
        ],
        'PIN_DIAMETER_MM' => [
            'labels' => ['pin mm'],
            'decision' => 'PROPOSED_CREATE',
            'code' => 'PIN-DIAMETER',
            'name' => 'Pin Diameter',
            'group' => 'mechanical',
            'dataType' => 'decimal',
            'unit' => 'mm',
            'confidence' => 'medium',
            'reason' => 'pin_mm_samples_are_diameters_not_counts',
            'existingInsufficient' => 'PIN-COUNT is integer pin quantity; samples are mm diameters.',
        ],
        'MIN_CU_MM2' => [
            'labels' => ['min cu mm²', 'min cu mm2'],
            'decision' => 'MAP_EXISTING',
            'code' => 'CSA',
            'name' => 'Conductor CSA',
            'group' => 'construction',
            'dataType' => 'text',
            'unit' => 'mm²',
            'confidence' => 'low',
            'reason' => 'min_conductor_csa_related_to_CSA_but_min_max_pair_needs_operator_confirm',
            'existingInsufficient' => null,
        ],
        'MAX_CU_MM2' => [
            'labels' => ['max cu mm²', 'max cu mm2'],
            'decision' => 'REVIEW',
            'code' => null,
            'name' => null,
            'group' => null,
            'dataType' => 'decimal',
            'unit' => 'mm²',
            'confidence' => 'low',
            'reason' => 'max_cu_range_bound_may_need_separate_or_range_definition',
            'existingInsufficient' => 'CSA is single value; max bound may need range or companion Definition.',
        ],
        'PARENT_CONNECTOR' => [
            'labels' => ['parent connector'],
            'decision' => 'REVIEW',
            'code' => null,
            'name' => null,
            'group' => 'construction',
            'dataType' => 'text',
            'unit' => null,
            'confidence' => 'low',
            'reason' => 'relationship_or_classification_not_clear_specification',
            'existingInsufficient' => 'May be identity/navigation, not a SpecificationDefinition.',
        ],
        'IND_LSZH_FIRE' => [
            'labels' => ['ind_lszh_fire'],
            'decision' => 'PROPOSE_DEFINITION',
            'code' => 'IND-LSZH-FIRE',
            'name' => 'Industrial LSZH / Fire',
            'group' => 'compliance',
            'dataType' => 'text',
            'unit' => null,
            'confidence' => 'medium',
            'reason' => 'wp_serialized_flags_lszh_fire_not_covered_by_existing_defs',
            'existingInsufficient' => 'Not STANDARD options alone; compound industrial flag set.',
            'special' => true,
        ],
        'NEK_CLASS' => [
            'labels' => ['nek_class'],
            'decision' => 'REVIEW',
            'code' => null,
            'name' => null,
            'group' => 'compliance',
            'dataType' => null,
            'unit' => null,
            'confidence' => 'low',
            'reason' => 'empty_samples_may_belong_under_STANDARD_options_not_new_definition',
            'existingInsufficient' => 'Insufficient non-empty values; prefer STANDARD option master later.',
            'special' => true,
        ],
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
        $readyProducts = is_array($inputs['classificationReadyProducts'] ?? null)
            ? $inputs['classificationReadyProducts'] : [];
        $operator = is_array($inputs['operatorDecisions'] ?? null) ? $inputs['operatorDecisions'] : [];
        $defsByCode = $this->indexDefs($azure['definitions'] ?? []);

        $mappings = $this->asMappingRows($batchC['mappings']['items'] ?? $batchC['mappings'] ?? []);
        $approvedPreserved = [];
        $unresolved = [];
        $rejected = [];
        foreach ($mappings as $row) {
            $d = (string) ($row['decision'] ?? '');
            if ($d === SpecFieldMappingDecisionState::APPROVED_MAP) {
                $approvedPreserved[] = $row;
            } elseif (in_array($d, [SpecFieldMappingDecisionState::PROPOSED_MAP, SpecFieldMappingDecisionState::REVIEW], true)) {
                $unresolved[] = $row;
            } elseif ($d === SpecFieldMappingDecisionState::REJECT) {
                $rejected[] = $row;
            }
        }

        $concepts = $this->buildConcepts($unresolved, $defsByCode, $operator);
        $typeConflicts = $this->analyseTypeConflicts($mappings, $concepts);
        $vacuousAudit = $this->auditVacuousReadiness($readyProducts, $mappings);
        $variantAudit = $this->auditVariantReadiness($readyProducts, $mappings, $vacuousAudit);
        $proposals = $this->buildProposals($concepts);
        $impact = $this->simulateImpact($readyProducts, $mappings, $concepts, $approvedPreserved);
        $mappingState = $this->projectMappingState($mappings, $concepts);
        $special = $this->specialReviews();

        $high = $med = $low = 0;
        foreach ($concepts as $c) {
            $conf = (string) ($c['confidence'] ?? 'low');
            if ($conf === 'high') {
                ++$high;
            } elseif ($conf === 'medium') {
                ++$med;
            } else {
                ++$low;
            }
        }

        $recommended = $this->recommendedPackage($concepts);

        return [
            'phase' => '12.7-batch-c1',
            'mode' => 'OPERATOR_REVIEW_DRY_RUN_ONLY',
            'batchC1Apply' => 'NOT_RUN',
            'batchCApply' => 'NOT_RUN',
            'approvalBoundary' => [
                'batchC1ApplyGuard' => 'BATCH_C1_APPLY_NOT_APPROVED',
                'batchCApplyGuard' => 'BATCH_C_APPLY_NOT_APPROVED',
                'noTargetWrites' => true,
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
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => 0,
            ],
            'startingBatchCState' => [
                'APPROVED_MAP' => count($approvedPreserved),
                'PROPOSED_MAP' => count(array_filter($unresolved, static fn (array $r): bool => ($r['decision'] ?? '') === SpecFieldMappingDecisionState::PROPOSED_MAP)),
                'REVIEW' => count(array_filter($unresolved, static fn (array $r): bool => ($r['decision'] ?? '') === SpecFieldMappingDecisionState::REVIEW)),
                'REJECT' => count($rejected),
            ],
            'unresolvedConcepts' => [
                'total' => count($concepts),
                'highConfidence' => $high,
                'mediumConfidence' => $med,
                'lowConfidence' => $low,
                'items' => $concepts,
            ],
            'definitionProposals' => $proposals,
            'typeConflicts' => $typeConflicts,
            'vacuousReadinessAudit' => $vacuousAudit,
            'variantReadinessAudit' => $variantAudit,
            'impactSimulation' => $impact,
            'specialReviews' => $special,
            'mappingStateAfterC1Review' => $mappingState,
            'recommendedOperatorApprovalPackage' => $recommended,
            'futureApplyPlan' => [
                'mode' => 'NOT_EXECUTED',
                'masterDataChanges' => [
                    'definitionsToCreate' => count($proposals['proposedCreate'] ?? []),
                    'definitionsToMapExisting' => count($proposals['mapExisting'] ?? []),
                    'optionsToCreate' => 0,
                    'unitsToCreate' => 0,
                ],
                'fieldMappingChanges' => [
                    'batchCApprovedMapsEligible' => count($approvedPreserved),
                    'additionalMapsAfterC1' => (int) ($mappingState['wouldBecomeApprovedIfPackageAccepted'] ?? 0),
                ],
                'guard' => 'BATCH_C1_APPLY_NOT_APPROVED',
            ],
            'targetWrites' => [
                'definitionsCreated' => 0,
                'mappingsInserted' => 0,
                'specificationValues' => 0,
                'products' => 0,
                'variants' => 0,
                'legacyWrites' => 0,
            ],
            'recommendation' => 'CONTINUE CONCEPT REVIEW — DO NOT APPLY BATCH C YET',
            'nextAction' => 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL',
        ];
    }

    /**
     * @param list<array<string, mixed>> $unresolved
     * @param array<string, array<string, mixed>> $defsByCode
     * @param array<string, mixed> $operator
     * @return list<array<string, mixed>>
     */
    private function buildConcepts(array $unresolved, array $defsByCode, array $operator): array
    {
        $labelToConcept = [];
        foreach (self::conceptCatalog() as $conceptKey => $meta) {
            $labels = $meta['labels'];
            foreach ($labels as $lab) {
                $labelToConcept[(string) $lab] = $conceptKey;
            }
        }

        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = [];
        foreach ($unresolved as $row) {
            $label = strtolower(trim((string) ($row['normalizedLabel'] ?? '')));
            $conceptKey = $labelToConcept[$label] ?? ('OTHER:' . ($label !== '' ? $label : 'unlabeled'));
            if (!isset($buckets[$conceptKey])) {
                $catalog = self::conceptCatalog()[$conceptKey] ?? null;
                $buckets[$conceptKey] = [
                    'concept' => $conceptKey,
                    'legacyLabels' => [],
                    'styles' => [],
                    'mappingRows' => 0,
                    'productsAffectedEstimate' => 0,
                    'sampleValues' => [],
                    'sourceFields' => [],
                    'startingDecisions' => [],
                    'detectedType' => null,
                    'detectedUnit' => null,
                    'existingTargetCandidate' => null,
                    'confidence' => 'low',
                    'decision' => 'REVIEW',
                    'reason' => 'ungrouped_or_low_evidence',
                    'proposedDefinition' => null,
                ];
                if ($catalog !== null) {
                    $code = (string) ($catalog['code'] ?? '');
                    $exists = $code !== '' && isset($defsByCode[$code]);
                    $decision = (string) $catalog['decision'];
                    if ($decision === 'MAP_EXISTING' && !$exists && $code !== '') {
                        $decision = 'REVIEW';
                    }
                    if ($decision === 'PROPOSED_CREATE' && $exists) {
                        $decision = 'MAP_EXISTING';
                    }
                    $buckets[$conceptKey]['decision'] = $decision;
                    $buckets[$conceptKey]['confidence'] = (string) $catalog['confidence'];
                    $buckets[$conceptKey]['reason'] = (string) $catalog['reason'];
                    $buckets[$conceptKey]['existingTargetCandidate'] = $exists ? $code : ($decision === 'MAP_EXISTING' ? $code : null);
                    $buckets[$conceptKey]['proposedDefinition'] = in_array($decision, ['PROPOSED_CREATE', 'PROPOSE_DEFINITION'], true)
                        ? [
                            'code' => $catalog['code'] ?? null,
                            'name' => $catalog['name'] ?? null,
                            'group' => $catalog['group'] ?? null,
                            'dataType' => $catalog['dataType'] ?? null,
                            'unit' => $catalog['unit'] ?? null,
                            'description' => (string) $catalog['reason'],
                            'reasonExistingDefinitionsInsufficient' => $catalog['existingInsufficient'] ?? null,
                        ]
                        : ($decision === 'MAP_EXISTING' ? [
                            'code' => $catalog['code'] ?? null,
                            'name' => $catalog['name'] ?? null,
                            'group' => $catalog['group'] ?? null,
                            'dataType' => $catalog['dataType'] ?? null,
                            'unit' => $catalog['unit'] ?? null,
                        ] : null);
                    $buckets[$conceptKey]['detectedType'] = $catalog['dataType'] ?? null;
                    $buckets[$conceptKey]['detectedUnit'] = $catalog['unit'] ?? null;
                }
            }
            ++$buckets[$conceptKey]['mappingRows'];
            $style = (string) ($row['styleCode'] ?? '');
            if ($style !== '') {
                $buckets[$conceptKey]['styles'][$style] = true;
            }
            $labRaw = (string) ($row['legacyLabel'] ?? $label);
            if ($labRaw !== '') {
                $buckets[$conceptKey]['legacyLabels'][$labRaw] = true;
            }
            $field = (string) ($row['sourceField'] ?? '');
            if ($field !== '') {
                $buckets[$conceptKey]['sourceFields'][$field] = true;
            }
            $sd = (string) ($row['decision'] ?? '');
            $buckets[$conceptKey]['startingDecisions'][$sd] = ($buckets[$conceptKey]['startingDecisions'][$sd] ?? 0) + 1;
            foreach (($row['sampleValues'] ?? []) as $sv) {
                if (is_string($sv) && $sv !== '' && count($buckets[$conceptKey]['sampleValues']) < 6) {
                    $buckets[$conceptKey]['sampleValues'][] = $sv;
                }
            }
        }

        // Operator overrides by concept key
        foreach ($operator as $key => $override) {
            if (!is_array($override) || !isset($buckets[(string) $key])) {
                continue;
            }
            if (isset($override['decision'])) {
                $buckets[(string) $key]['decision'] = (string) $override['decision'];
            }
            if (isset($override['reason'])) {
                $buckets[(string) $key]['reason'] = (string) $override['reason'];
            }
            if (isset($override['targetDefinitionCode'])) {
                $buckets[(string) $key]['existingTargetCandidate'] = (string) $override['targetDefinitionCode'];
            }
        }

        $out = [];
        foreach ($buckets as $c) {
            $c['stylesAffected'] = count($c['styles']);
            $c['productsAffectedEstimate'] = $c['stylesAffected'];
            $c['legacyLabels'] = array_keys($c['legacyLabels']);
            $c['sourceFields'] = array_keys($c['sourceFields']);
            unset($c['styles']);
            // Integer sample gate for PIN_COUNT_LABEL
            if (($c['concept'] ?? '') === 'PIN_COUNT_LABEL') {
                $intOk = true;
                foreach ($c['sampleValues'] as $sv) {
                    if (!preg_match('/^-?\d+(\.0+)?$/', (string) $sv)) {
                        $intOk = false;
                        break;
                    }
                }
                if (!$intOk) {
                    $c['decision'] = 'REVIEW';
                    $c['reason'] = 'pin_count_samples_not_integer_compatible';
                    $c['confidence'] = 'low';
                    $c['existingTargetCandidate'] = null;
                }
            }
            $out[] = $c;
        }
        usort($out, static function (array $a, array $b): int {
            return ($b['productsAffectedEstimate'] <=> $a['productsAffectedEstimate'])
                ?: ($b['mappingRows'] <=> $a['mappingRows'])
                ?: ($b['stylesAffected'] <=> $a['stylesAffected']);
        });

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @param list<array<string, mixed>> $concepts
     * @return array<string, mixed>
     */
    private function analyseTypeConflicts(array $mappings, array $concepts): array
    {
        $items = [];
        foreach ($mappings as $row) {
            if (($row['typeCompatible'] ?? null) !== false) {
                continue;
            }
            $label = strtolower((string) ($row['normalizedLabel'] ?? ''));
            $resolution = 'KEEP_REVIEW';
            $recommendedTarget = null;
            if ($label === 'phase') {
                $resolution = 'MAP_EXISTING';
                $recommendedTarget = 'CORE-COLOURS';
            }
            $items[] = [
                'style' => $row['styleCode'] ?? null,
                'sourceField' => $row['sourceField'] ?? null,
                'legacyLabel' => $row['legacyLabel'] ?? null,
                'sampleValues' => $row['sampleValues'] ?? [],
                'candidateDefinition' => $row['targetDefinitionCode'] ?? null,
                'candidateType' => $row['targetDataType'] ?? null,
                'observedSourceType' => 'text_colour_token',
                'reason' => $row['reason'] ?? 'type_incompatible',
                'recommendedResolution' => $resolution,
                'recommendedTarget' => $recommendedTarget,
            ];
        }

        $resolved = count(array_filter($items, static fn (array $i): bool => ($i['recommendedResolution'] === 'MAP_EXISTING')));

        return [
            'before' => count($items),
            'resolvedByC1Recommendation' => $resolved,
            'remaining' => count($items) - $resolved,
            'items' => $items,
        ];
    }

    /**
     * @param list<mixed> $products
     * @param list<array<string, mixed>> $mappings
     * @return array<string, mixed>
     */
    private function auditVacuousReadiness(array $products, array $mappings): array
    {
        $dvStyles = [];
        foreach ($mappings as $row) {
            $s = strtoupper((string) ($row['styleCode'] ?? ''));
            if ($s !== '' && $s !== '*') {
                $dvStyles[$s] = true;
            }
        }

        $notApplicable = [];
        $complete = [];
        $incomplete = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $family = strtoupper((string) ($p['family'] ?? ''));
            $entry = [
                'styleCode' => $style,
                'family' => $family,
                'productTitle' => $p['productTitle'] ?? null,
                'variantCount' => (int) ($p['variantCount'] ?? 0),
            ];
            if (!isset($dvStyles[$style])) {
                if ($family === 'CABLE' || $family === '') {
                    $entry['reason'] = 'CABLE_SPEC_SOURCE_USED';
                    $entry['classification'] = 'FIELD_MAPPING_NOT_APPLICABLE';
                    $entry['note'] = 'Accessory d/v fields do not apply; cable_specs / WP meta still require separate mapping completeness.';
                    $notApplicable[] = $entry;
                } else {
                    $entry['reason'] = 'NO_DV_FIELDS';
                    $entry['classification'] = 'FIELD_MAPPING_NOT_APPLICABLE';
                    $notApplicable[] = $entry;
                }
                continue;
            }
            // Has d/v — completeness determined elsewhere
            $incomplete[] = $entry + [
                'reason' => 'HAS_DV_FIELDS',
                'classification' => 'FIELD_MAPPING_INCOMPLETE_OR_COMPLETE_VIA_APPROVALS',
            ];
        }

        return [
            'previouslyVacuousReadyProducts' => count($notApplicable),
            'FIELD_MAPPING_NOT_APPLICABLE' => count($notApplicable),
            'FIELD_MAPPING_COMPLETE' => count($complete),
            'incompleteOrUnknown' => count($incomplete),
            'policyCorrection' => 'Vacuous cable styles must NOT count as SPEC_FIELD_MAPPING_READY. Use FIELD_MAPPING_NOT_APPLICABLE.',
            'itemsNotApplicable' => $notApplicable,
            'byReason' => $this->countBy($notApplicable, 'reason'),
        ];
    }

    /**
     * @param list<mixed> $products
     * @param list<array<string, mixed>> $mappings
     * @param array<string, mixed> $vacuousAudit
     * @return array<string, mixed>
     */
    private function auditVariantReadiness(array $products, array $mappings, array $vacuousAudit): array
    {
        $dvStyles = [];
        foreach ($mappings as $row) {
            $s = strtoupper((string) ($row['styleCode'] ?? ''));
            if ($s !== '' && $s !== '*') {
                $dvStyles[$s] = true;
            }
        }

        $dvNa = 0;
        $complete = 0;
        $incomplete = 0;
        $unknown = 0;
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $style = strtoupper((string) ($p['style_code'] ?? ''));
            $variants = (int) ($p['variantCount'] ?? 0);
            $family = strtoupper((string) ($p['family'] ?? ''));
            if (!isset($dvStyles[$style])) {
                if ($family === 'CABLE' || $family === '') {
                    $dvNa += $variants;
                } else {
                    $unknown += $variants;
                }
                continue;
            }
            $rows = array_values(array_filter(
                $mappings,
                static fn (array $r): bool => strtoupper((string) ($r['styleCode'] ?? '')) === $style,
            ));
            $allApproved = true;
            foreach ($rows as $row) {
                $d = (string) ($row['decision'] ?? '');
                if ($d === SpecFieldMappingDecisionState::APPROVED_MAP || $d === SpecFieldMappingDecisionState::NOT_APPLICABLE) {
                    continue;
                }
                $allApproved = false;
                break;
            }
            if ($allApproved) {
                $complete += $variants;
            } else {
                $incomplete += $variants;
            }
        }

        $cap = 4178;
        return [
            'previouslyProjectedReady' => 4178,
            'VARIANT_DV_NOT_APPLICABLE' => min($dvNa, $cap),
            'VARIANT_SPEC_MAPPING_COMPLETE' => min($complete, $cap),
            'VARIANT_SPEC_MAPPING_INCOMPLETE' => min($incomplete, $cap),
            'VARIANT_SPEC_SOURCE_UNKNOWN' => min($unknown, $cap),
            'policyCorrection' => 'Do not equate DV_NOT_APPLICABLE with SPEC_MAPPING_COMPLETE for cable variants.',
            'vacuousPolicy' => $vacuousAudit['policyCorrection'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @return array<string, mixed>
     */
    private function buildProposals(array $concepts): array
    {
        $mapExisting = [];
        $proposedCreate = [];
        $review = [];
        foreach ($concepts as $c) {
            $d = (string) ($c['decision'] ?? 'REVIEW');
            if ($d === 'MAP_EXISTING') {
                $mapExisting[] = $c;
            } elseif (in_array($d, ['PROPOSED_CREATE', 'PROPOSE_DEFINITION'], true)) {
                $proposedCreate[] = $c;
            } else {
                $review[] = $c;
            }
        }

        return [
            'mapExisting' => $mapExisting,
            'proposedCreate' => $proposedCreate,
            'review' => $review,
            'counts' => [
                'mapExisting' => count($mapExisting),
                'proposedCreate' => count($proposedCreate),
                'review' => count($review),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $mappings
     * @param list<array<string, mixed>> $concepts
     * @param list<array<string, mixed>> $approved
     * @return array<string, mixed>
     */
    private function simulateImpact(array $products, array $mappings, array $concepts, array $approved): array
    {
        $byStyle = [];
        foreach ($mappings as $row) {
            $s = strtoupper((string) ($row['styleCode'] ?? ''));
            if ($s === '' || $s === '*') {
                continue;
            }
            $byStyle[$s][] = $row;
        }

        $labelToDecision = [];
        foreach ($concepts as $c) {
            $decision = (string) ($c['decision'] ?? 'REVIEW');
            $target = $c['existingTargetCandidate']
                ?? ($c['proposedDefinition']['code'] ?? null);
            $cat = self::conceptCatalog()[$c['concept']] ?? null;
            $catLabels = is_array($cat) ? ($cat['labels'] ?? []) : [];
            if (!is_array($catLabels)) {
                $catLabels = [];
            }
            foreach ($catLabels as $lab) {
                $labelToDecision[(string) $lab] = [
                    'decision' => $decision,
                    'target' => $target,
                    'concept' => $c['concept'],
                ];
            }
            // Also index by normalized legacy labels on the concept bucket
            foreach ($c['legacyLabels'] as $lab) {
                $norm = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $lab))) ?? ''));
                if ($norm !== '') {
                    $labelToDecision[$norm] = [
                        'decision' => $decision,
                        'target' => $target,
                        'concept' => $c['concept'],
                    ];
                }
            }
        }

        $scenarioA = $this->countReady($products, $byStyle, 'A', $labelToDecision);
        $scenarioB = $this->countReady($products, $byStyle, 'B', $labelToDecision);
        $scenarioC = $this->countReady($products, $byStyle, 'C', $labelToDecision);

        return [
            'CURRENT' => [
                'SPEC_FIELD_MAPPING_READY' => 0,
                'FULL_PRODUCT_MIGRATION_READY' => 54,
                'FULL_PRODUCT_MIGRATION_BLOCKED' => 336,
                'note' => 'Mappings not applied; vacuous no longer counted as ready.',
            ],
            'SCENARIO_A_CURRENT_124_ONLY' => $scenarioA,
            'SCENARIO_B_PLUS_MAP_EXISTING' => $scenarioB,
            'SCENARIO_C_PLUS_PROPOSED_DEFINITIONS' => $scenarioC,
            'approvedMapsPreserved' => count($approved),
        ];
    }

    /**
     * @param list<mixed> $products
     * @param array<string, list<array<string, mixed>>> $byStyle
     * @param array<string, array<string, mixed>> $labelToDecision
     * @return array<string, mixed>
     */
    private function countReady(array $products, array $byStyle, string $scenario, array $labelToDecision): array
    {
        $ready = 0;
        $review = 0;
        $notApplicable = 0;
        $accessoryReady = 0;
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $style = strtoupper((string) ($p['style_code'] ?? ''));
            $family = strtoupper((string) ($p['family'] ?? ''));
            $rows = $byStyle[$style] ?? [];
            if ($rows === []) {
                ++$notApplicable;
                continue;
            }
            $allOk = true;
            foreach ($rows as $row) {
                $d = (string) ($row['decision'] ?? '');
                if ($d === SpecFieldMappingDecisionState::APPROVED_MAP) {
                    continue;
                }
                $label = strtolower(trim((string) ($row['normalizedLabel'] ?? '')));
                $resolved = $labelToDecision[$label] ?? null;
                if ($resolved === null) {
                    $allOk = false;
                    break;
                }
                $rd = (string) ($resolved['decision'] ?? '');
                if ($scenario === 'A') {
                    $allOk = false;
                    break;
                }
                if ($scenario === 'B' && $rd === 'MAP_EXISTING') {
                    continue;
                }
                if ($scenario === 'C' && in_array($rd, ['MAP_EXISTING', 'PROPOSED_CREATE', 'PROPOSE_DEFINITION'], true)) {
                    continue;
                }
                $allOk = false;
                break;
            }
            if ($allOk) {
                ++$ready;
                if ($family === 'ACCESSORIES') {
                    ++$accessoryReady;
                }
            } else {
                ++$review;
            }
        }

        // Full migration still gated by accessory identity / media etc.
        $fullReady = 54;
        $fullBlocked = 336;

        return [
            'SPEC_FIELD_MAPPING_READY' => $ready,
            'SPEC_FIELD_MAPPING_REVIEW' => $review,
            'FIELD_MAPPING_NOT_APPLICABLE' => $notApplicable,
            'accessoryProductsFieldReady' => $accessoryReady,
            'FULL_PRODUCT_MIGRATION_READY' => $fullReady,
            'FULL_PRODUCT_MIGRATION_BLOCKED' => $fullBlocked,
            'note' => 'FULL_* unchanged until accessory identity + value migration solved; field-mapping ready is independent.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @param list<array<string, mixed>> $concepts
     * @return array<string, mixed>
     */
    private function projectMappingState(array $mappings, array $concepts): array
    {
        $labelRes = [];
        foreach ($concepts as $c) {
            $cat = self::conceptCatalog()[$c['concept']] ?? null;
            $catLabels = is_array($cat) ? ($cat['labels'] ?? []) : [];
            if (!is_array($catLabels)) {
                $catLabels = [];
            }
            foreach ($catLabels as $lab) {
                $labelRes[(string) $lab] = (string) ($c['decision'] ?? 'REVIEW');
            }
        }

        $approved = 0;
        $dependent = 0;
        $proposed = 0;
        $review = 0;
        $reject = 0;
        $wouldApprove = 0;
        foreach ($mappings as $row) {
            $d = (string) ($row['decision'] ?? '');
            $label = strtolower(trim((string) ($row['normalizedLabel'] ?? '')));
            if ($d === SpecFieldMappingDecisionState::APPROVED_MAP) {
                ++$approved;
                continue;
            }
            if ($d === SpecFieldMappingDecisionState::REJECT) {
                ++$reject;
                continue;
            }
            $rd = $labelRes[$label] ?? null;
            if ($rd === 'MAP_EXISTING') {
                ++$wouldApprove;
                ++$proposed; // still proposed until operator+apply
            } elseif (in_array($rd, ['PROPOSED_CREATE', 'PROPOSE_DEFINITION'], true)) {
                ++$dependent;
            } elseif ($d === SpecFieldMappingDecisionState::PROPOSED_MAP) {
                ++$proposed;
            } else {
                ++$review;
            }
        }

        return [
            'APPROVED_MAP' => $approved,
            'DEPENDENT_ON_NEW_DEFINITION' => $dependent,
            'PROPOSED_MAP' => $proposed,
            'REVIEW' => $review,
            'REJECT' => $reject,
            'wouldBecomeApprovedIfPackageAccepted' => $wouldApprove,
            'beforeReview' => 584,
            'afterConceptResolutionReviewApprox' => $review,
            'resolvedViaExistingDefinitionApprox' => $wouldApprove,
            'resolvedViaProposedNewDefinitionApprox' => $dependent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specialReviews(): array
    {
        return [
            'ind_lszh_fire' => [
                'decision' => 'PROPOSE_DEFINITION',
                'code' => 'IND-LSZH-FIRE',
                'evidence' => 'WP meta usageCount=120; serialized values include lszh/fire flags',
                'notes' => 'Not a duplicate of STANDARD alone; compound industrial construction/compliance flag.',
            ],
            'nek_class' => [
                'decision' => 'REVIEW',
                'code' => null,
                'evidence' => 'usageCount=55 but empty samples in discovery',
                'notes' => 'Likely Standard classification → prefer STANDARD options later; do not create free-text Definition yet.',
            ],
            'LENGTH' => [
                'decision' => 'SPLIT',
                'subgroups' => [
                    'OVERALL_LENGTH_MM' => 'length mm / length (l) mm → propose LENGTH (mm)',
                    'CABLE_LENGTH_M' => 'length m → propose LENGTH-M (m)',
                    'THREAD_LENGTH_MM' => 'thread length mm → propose THREAD-LENGTH',
                    'BARREL_LENGTH_MM' => 'barrel mm → propose BARREL-LENGTH (not overall length)',
                ],
            ],
            'WIDTH' => [
                'decision' => 'PROPOSED_CREATE',
                'code' => 'WIDTH',
                'subgroups' => [
                    'WIDTH_MM' => 'width mm / width(w) mm — single WIDTH Definition sufficient on current evidence',
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @return array<string, mixed>
     */
    private function recommendedPackage(array $concepts): array
    {
        $map = [];
        $create = [];
        foreach ($concepts as $c) {
            $d = (string) ($c['decision'] ?? '');
            $conf = (string) ($c['confidence'] ?? '');
            if ($d === 'MAP_EXISTING' && in_array($conf, ['high', 'medium'], true)) {
                $map[] = [
                    'concept' => $c['concept'],
                    'target' => $c['existingTargetCandidate'],
                    'mappings' => $c['mappingRows'],
                    'styles' => $c['stylesAffected'],
                ];
            }
            if (in_array($d, ['PROPOSED_CREATE', 'PROPOSE_DEFINITION'], true) && in_array($conf, ['high', 'medium'], true)) {
                $create[] = [
                    'concept' => $c['concept'],
                    'code' => $c['proposedDefinition']['code'] ?? null,
                    'name' => $c['proposedDefinition']['name'] ?? null,
                    'group' => $c['proposedDefinition']['group'] ?? null,
                    'dataType' => $c['proposedDefinition']['dataType'] ?? null,
                    'unit' => $c['proposedDefinition']['unit'] ?? null,
                    'mappings' => $c['mappingRows'],
                    'styles' => $c['stylesAffected'],
                    'confidence' => $conf,
                ];
            }
        }

        $mapRows = array_sum(array_column($map, 'mappings'));
        $createRows = array_sum(array_column($create, 'mappings'));

        return [
            'definitionsToMapExisting' => $map,
            'definitionsProposedForCreate' => $create,
            'styleFieldMappingsThatWouldBecomeEligible' => $mapRows + $createRows,
            'expectedProductReadinessImprovement' => 'Field-mapping complete for accessory styles with only packaged concepts; cables remain FIELD_MAPPING_NOT_APPLICABLE until cable_specs mapping. FULL_PRODUCT_MIGRATION_READY unchanged (~54).',
            'doNotCreateLowConfidence' => true,
        ];
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
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (string) ($r[$key] ?? 'OTHER');
            $out[$k] = ($out[$k] ?? 0) + 1;
        }

        return $out;
    }
    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function asMappingRows(mixed $value): array
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
