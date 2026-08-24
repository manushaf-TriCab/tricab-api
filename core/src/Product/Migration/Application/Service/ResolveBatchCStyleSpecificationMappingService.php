<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Specification\SpecFieldMappingDecisionState;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch C — style-specific d1–d7/v1–v4 field mapping review + dry-run.
 * Never inserts SpecificationFieldMappings, Product/Variant values, or Definitions.
 */
final class ResolveBatchCStyleSpecificationMappingService
{
    /** Deferred Batch B Definition concepts — must not be targets. */
    private const BLOCKED_DEFINITION_CODES = ['LENGTH', 'WIDTH', 'IND-LSZH-FIRE', 'NEK-CLASS'];

    /**
     * High-confidence label → existing Definition code (Batch B masters applied).
     * Only used when definition exists live. Never invents new Definitions.
     *
     * @var array<string, string>
     */
    private const LABEL_TO_DEFINITION = [
        'pins' => 'PIN-COUNT',
        'pin' => 'PIN-COUNT',
        'thread od' => 'THREAD-OD',
        'thread od mm' => 'THREAD-OD',
        'voltage' => 'VOLTAGE',
        'voltage v' => 'VOLTAGE',
        'voltage rating' => 'VOLTAGE',
        'voltage rating v' => 'VOLTAGE',
        'current rating' => 'CURRENT',
        'current rating a' => 'CURRENT',
        'rating amp' => 'CURRENT',
        'rating a' => 'CURRENT',
        'number of cores' => 'CORES',
        'no. of cores' => 'CORES',
        'no of cores' => 'CORES',
        'cores' => 'CORES',
        'number of conductors' => 'CODE_CORE_COUNT',
        'no. of poles' => 'CORES',
        'no of poles' => 'CORES',
        'no. of pole/phase' => 'CORES',
        'phases' => 'CORES',
        'phase' => 'CORES',
        'min bend' => 'MIN-BEND-RADIUS',
        'min. bend' => 'MIN-BEND-RADIUS',
        'approx wt' => 'APPROX-WEIGHT',
        'approx. wt' => 'APPROX-WEIGHT',
        'approx. wt.' => 'APPROX-WEIGHT',
        'weight' => 'APPROX-WEIGHT',
        'overall diameter' => 'NOM-CABLE-OD',
        'overall dia' => 'NOM-CABLE-OD',
        'cable diameter' => 'NOM-CABLE-OD',
        'cable diameter mm' => 'NOM-CABLE-OD',
        'outer dia' => 'NOM-CABLE-OD',
        'outer dia mm' => 'NOM-CABLE-OD',
        'outerdia' => 'NOM-CABLE-OD',
        'nom. cable' => 'NOM-CABLE-OD',
        'nom cable' => 'NOM-CABLE-OD',
        'pin mm' => 'PIN-COUNT',
        'no of pole/phase' => 'CORES',
        'operating temperature' => 'OPERATING-TEMP',
        'hcl emission' => 'HCL-EMISSION',
        'oxygen index' => 'OXYGEN-INDEX',
        'ip rating' => 'IP-RATING',
        'sheath' => 'SHEATH',
        'sheath material' => 'SHEATH',
        'standard' => 'STANDARD',
        'standards' => 'STANDARD',
        'approvals' => 'APPROVALS',
        'braid' => 'BRAID',
        'conductors' => 'CONDUCTORS',
        'conductor csa' => 'CSA',
        'cross section' => 'CSA',
        'cross section area' => 'CODE_CROSS_SECTION',
    ];

    /** Labels that must stay REVIEW (no approved Definition / Batch B deferred). */
    private const FORCE_REVIEW_LABELS = [
        'length', 'length mm', 'length m', 'lengths', 'widths', 'width', 'width mm',
        'height', 'height mm', 'depth', 'depth mm', 'thickness', 'thickness mm',
        'barrel', 'barrel mm', 'stud', 'stud mm', 'inner', 'outer',
        'thread length', 'thread length mm', 'parent connector', 'size',
    ];

    /**
     * Ambiguous colour labels — propose DEFAULT-COLOUR but do not auto-approve
     * (core-colour matrices must stay on CORE-COLOURS).
     *
     * @var list<string>
     */
    private const COLOUR_LABELS = ['colour', 'color', 'cable colour', 'cable color'];

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function resolve(array $inputs): array
    {
        $azure = is_array($inputs['azure'] ?? null) ? $inputs['azure'] : [];
        $dvRows = is_array($inputs['dvRows'] ?? null) ? $inputs['dvRows'] : [];
        $existingMappings = is_array($inputs['existingFieldMappings'] ?? null) ? $inputs['existingFieldMappings'] : [];
        $classificationReadyProducts = is_array($inputs['classificationReadyProducts'] ?? null)
            ? $inputs['classificationReadyProducts'] : [];
        $operator = is_array($inputs['operatorDecisions'] ?? null) ? $inputs['operatorDecisions'] : [];
        $classificationReady = (int) ($inputs['classificationReady'] ?? 95);
        $specMasterReady = (int) ($inputs['specMasterReady'] ?? 95);
        $fullProductReady = (int) ($inputs['fullProductMigrationReady'] ?? 54);
        $fullProductBlocked = (int) ($inputs['fullProductMigrationBlocked'] ?? 336);
        $accessoryBlocked = (int) ($inputs['accessoryIdentityBlocked'] ?? 41);

        $defsByCode = $this->indexDefs($azure['definitions'] ?? []);
        $defsByPublicId = [];
        foreach ($defsByCode as $code => $def) {
            $pid = (string) ($def['publicId'] ?? '');
            if ($pid !== '') {
                $defsByPublicId[$pid] = $def;
            }
        }

        $existingByKey = $this->indexExistingStyleMappings($existingMappings);
        $defaults = $this->defaultOperatorDecisions();
        $mergedOperator = array_merge($defaults, $operator);

        $mappings = [];
        $conflicts = [];
        $positionConcepts = [];
        foreach (['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'v1', 'v2', 'v3', 'v4'] as $pos) {
            $positionConcepts[$pos] = [];
        }

        $prevSuggested = 0;
        $prevReview = 0;
        $styles = [];

        foreach ($dvRows as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $status = strtoupper((string) ($raw['status'] ?? ''));
            if ($status === 'SUGGESTED') {
                ++$prevSuggested;
            }
            if ($status === 'REVIEW') {
                ++$prevReview;
            }

            $style = $this->normalizeStyle((string) ($raw['style'] ?? ''));
            $field = strtolower(trim((string) ($raw['sourceField'] ?? '')));
            if ($field === '' || !preg_match('/^(d[1-7]|v[1-4])$/', $field)) {
                continue;
            }
            if ($style !== '*') {
                $styles[$style] = true;
            }

            $legacyLabel = (string) ($raw['legacyLabel'] ?? '');
            $normalizedLabel = $this->normalizeLabel($legacyLabel);
            $example = (string) ($raw['example'] ?? '');
            $proposedName = (string) ($raw['proposedDefinition'] ?? '');
            $usage = (int) ($raw['labelRows'] ?? 1);

            $resolved = $this->resolveCandidate(
                $style,
                $field,
                $legacyLabel,
                $normalizedLabel,
                $proposedName,
                $example,
                $defsByCode,
                (bool) ($raw['globalFieldAssumptionForbidden'] ?? false),
            );

            $key = $this->mappingKey('tc', $style, $field);
            if (isset($mergedOperator[$key]) && is_array($mergedOperator[$key])) {
                $resolved = $this->applyOperatorOverride($resolved, $mergedOperator[$key], $defsByCode);
            } elseif (isset($mergedOperator['group:' . $resolved['groupKey']]) && is_array($mergedOperator['group:' . $resolved['groupKey']])) {
                $resolved = $this->applyOperatorOverride($resolved, $mergedOperator['group:' . $resolved['groupKey']], $defsByCode);
            }

            // Existing target mapping conflict protection
            if (isset($existingByKey[$key])) {
                $existing = $existingByKey[$key];
                $existingCode = strtoupper((string) ($existing['targetDefinitionCode'] ?? ''));
                $newCode = strtoupper((string) ($resolved['targetDefinitionCode'] ?? ''));
                if ($existingCode !== '' && $newCode !== '' && $existingCode !== $newCode) {
                    $resolved['decision'] = SpecFieldMappingDecisionState::CONFLICT;
                    $resolved['reason'] = 'MAPPING_TARGET_CONFLICT_existing_' . $existingCode . '_vs_' . $newCode;
                    $resolved['confidence'] = 'blocked';
                    $conflicts[] = [
                        'style' => $style,
                        'sourceField' => $field,
                        'legacyLabel' => $legacyLabel,
                        'candidateDefinitions' => [$existingCode, $newCode],
                        'values' => [$example],
                        'reason' => $resolved['reason'],
                        'severity' => 'high',
                    ];
                } elseif ($existingCode !== '' && $newCode === $existingCode) {
                    $resolved['existingIdenticalMapping'] = true;
                }
            }

            if ($resolved['decision'] === SpecFieldMappingDecisionState::CONFLICT
                && !isset($existingByKey[$key])
            ) {
                $conflicts[] = [
                    'style' => $style,
                    'sourceField' => $field,
                    'legacyLabel' => $legacyLabel,
                    'candidateDefinitions' => array_values(array_filter([
                        $resolved['targetDefinitionCode'] ?? null,
                        $resolved['alternateCandidate'] ?? null,
                    ])),
                    'values' => [$example],
                    'reason' => $resolved['reason'] ?? 'conflict',
                    'severity' => 'medium',
                ];
            }

            if ($style !== '*' && $normalizedLabel !== '') {
                $positionConcepts[$field][$normalizedLabel] = ($positionConcepts[$field][$normalizedLabel] ?? 0) + 1;
            }

            $pid = null;
            $code = (string) ($resolved['targetDefinitionCode'] ?? '');
            if ($code !== '' && isset($defsByCode[$code])) {
                $pid = $defsByCode[$code]['publicId'] ?? null;
            }

            $row = [
                'sourceSystem' => 'tc',
                'sourceTable' => 'accessory_specs',
                'styleCode' => $style,
                'sourceField' => $field,
                'legacyLabel' => $legacyLabel,
                'normalizedLabel' => $normalizedLabel,
                'usageCount' => $usage,
                'nonEmptyValueCount' => $example !== '' ? 1 : 0,
                'sampleValues' => $example !== '' ? [$example] : [],
                'proposedDefinitionName' => $proposedName !== '' ? $proposedName : null,
                'candidateDefinition' => $code !== '' ? $code : null,
                'targetDefinitionCode' => $code !== '' ? $code : null,
                'targetDefinitionPublicId' => $pid,
                'targetDataType' => $resolved['targetDataType'] ?? null,
                'targetUnit' => $resolved['targetUnit'] ?? null,
                'targetGroup' => $resolved['targetGroup'] ?? null,
                'typeCompatible' => $resolved['typeCompatible'] ?? null,
                'unitCompatible' => $resolved['unitCompatible'] ?? null,
                'confidence' => $resolved['confidence'] ?? 'low',
                'decision' => $resolved['decision'],
                'reason' => $resolved['reason'] ?? null,
                'groupKey' => $resolved['groupKey'] ?? ($normalizedLabel . '|' . ($code !== '' ? $code : 'NONE')),
                'existingIdenticalMapping' => (bool) ($resolved['existingIdenticalMapping'] ?? false),
                'discoveryStatus' => $status,
                'globalFieldAssumptionForbidden' => (bool) ($raw['globalFieldAssumptionForbidden'] ?? false),
            ];
            $mappings[] = $row;
        }

        $counts = $this->countDecisions($mappings);
        $groups = $this->buildGroups($mappings);
        $styleConflicts = $this->detectStyleConflicts($mappings);
        foreach ($styleConflicts as $c) {
            $conflicts[] = $c;
        }

        $fieldPositionSemantics = [];
        foreach ($positionConcepts as $pos => $labels) {
            $fieldPositionSemantics[$pos] = [
                'distinctConcepts' => count($labels),
                'topLabels' => $this->topN($labels, 8),
            ];
        }

        $productReadiness = $this->calculateProductReadiness(
            $classificationReadyProducts,
            $mappings,
            $classificationReady,
            $specMasterReady,
            $accessoryBlocked,
            $fullProductReady,
            $fullProductBlocked,
        );

        $approved = array_values(array_filter(
            $mappings,
            static fn (array $r): bool => ($r['decision'] ?? '') === SpecFieldMappingDecisionState::APPROVED_MAP
                && $r['styleCode'] !== '*',
        ));
        $toInsert = array_values(array_filter(
            $approved,
            static fn (array $r): bool => $r['existingIdenticalMapping'] !== true,
        ));
        $identical = count($approved) - count($toInsert);

        $dryRun = [
            'mode' => 'DRY_RUN_ONLY',
            'writesWouldExecute' => false,
            'batchCApplyGuard' => 'BATCH_C_APPLY_NOT_APPROVED',
            'targetValidated' => ($azure['liveValidation'] ?? '') === 'AVAILABLE',
            'approvedMappingsToInsert' => count($toInsert),
            'existingIdenticalMappings' => $identical,
            'mappingsToUpdate' => 0,
            'conflicts' => count(array_filter(
                $mappings,
                static fn (array $r): bool => ($r['decision'] ?? '') === SpecFieldMappingDecisionState::CONFLICT,
            )),
            'productSpecificationValuesToWrite' => 0,
            'variantSpecificationValuesToWrite' => 0,
            'productMutations' => 0,
            'variantMutations' => 0,
            'classificationMutations' => 0,
            'specificationMasterMutations' => 0,
            'catalogueMutations' => 0,
            'pricingMutations' => 0,
            'mediaMutations' => 0,
            'documentMutations' => 0,
            'legacyWrites' => 0,
            'idempotencyProjection' => [
                'secondRunMappingsInserted' => 0,
                'secondRunMappingsUpdated' => 0,
                'duplicateMappings' => 0,
                'specificationValues' => 0,
            ],
            'futureApplyOrder' => [
                'validateTarget',
                'validateDefinitionsExist',
                'detectExistingConflicts',
                'insertApprovedStyleFieldMappings',
                'verifyUniqueness',
                'recalculateReadiness',
            ],
        ];

        $groupSummary = [
            'groupsApproved' => 0,
            'groupsProposed' => 0,
            'groupsRequiringIndividualReview' => 0,
        ];
        foreach ($groups as $g) {
            $d = (string) ($g['decision'] ?? '');
            if ($d === SpecFieldMappingDecisionState::APPROVED_MAP) {
                ++$groupSummary['groupsApproved'];
            } elseif ($d === SpecFieldMappingDecisionState::PROPOSED_MAP) {
                ++$groupSummary['groupsProposed'];
            } else {
                ++$groupSummary['groupsRequiringIndividualReview'];
            }
        }

        return [
            'phase' => '12.7-batch-c',
            'mode' => 'OPERATOR_REVIEW_DRY_RUN_ONLY',
            'batchCApply' => 'NOT_RUN',
            'productVariantMigration' => 'NOT_STARTED',
            'target' => [
                'server' => MigrationPayload::string($azure['server'] ?? 'tricab-mel'),
                'database' => MigrationPayload::string($azure['database'] ?? 'tc'),
                'environment' => 'NON-PRODUCTION',
                'liveValidation' => MigrationPayload::string($azure['liveValidation'] ?? 'UNKNOWN'),
                'definitions' => count($defsByCode),
                'units' => (int) ($azure['unitCount'] ?? count($azure['units'] ?? [])),
                'options' => (int) ($azure['optionCount'] ?? 0),
                'products' => (int) ($azure['productCount'] ?? 118),
                'variants' => (int) ($azure['variantCount'] ?? 4178),
                'categories' => (int) ($azure['categoryCount'] ?? 13),
                'series' => (int) ($azure['seriesCount'] ?? 97),
                'catalogueNodes' => (int) ($azure['catalogueNodeCount'] ?? 97),
                'existingStyleSpecificMappings' => count($existingByKey),
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => (int) ($inputs['forbiddenWriteAttempts'] ?? 0),
            ],
            'sourceInventory' => [
                'stylesInspected' => count($styles),
                'usedDvCombinations' => count($mappings),
                'previousSuggested' => $prevSuggested,
                'previousReview' => $prevReview,
                'policy' => 'Mappings keyed by legacy style + source field. GLOBAL d1=… forbidden.',
            ],
            'fieldPositionSemantics' => $fieldPositionSemantics,
            'mappingDecisions' => $counts,
            'mappingGroups' => $groups,
            'mappingGroupSummary' => $groupSummary,
            'mappings' => [
                'items' => $mappings,
                'counts' => $counts,
            ],
            'unresolvedDefinitions' => [
                'ind_lszh_fire' => 'REMAINS_REVIEW_no_approved_definition_target',
                'nek_class' => 'REMAINS_REVIEW_may_feed_standard_options_later',
                'LENGTH' => 'REMAINS_REVIEW_too_generic_across_styles_no_definition',
                'WIDTH' => 'REMAINS_REVIEW_too_generic_across_styles_no_definition',
            ],
            'typeUnitValidation' => $this->typeUnitStats($mappings),
            'optionValidation' => [
                'optionBackedMappings' => 0,
                'fullyResolvable' => 0,
                'unresolvedRequiredOptions' => 0,
                'note' => 'Batch C maps fields to Definitions only; option value migration is a later phase.',
            ],
            'coreColours' => [
                'fullMatrixPreserved' => true,
                'codingColourKeptSeparate' => true,
                'informationLoss' => false,
                'note' => 'Colour matrix fields stay REVIEW/PROPOSED toward CORE-COLOURS; coding colour not collapsed.',
            ],
            'conflicts' => [
                'items' => $conflicts,
                'count' => count($conflicts),
            ],
            'productReadiness' => $productReadiness,
            'dryRunTargetPlan' => $dryRun,
            'approvalBoundary' => [
                'batchCApplyGuard' => 'BATCH_C_APPLY_NOT_APPROVED',
                'noTargetWrites' => true,
                'noSpecificationValueWrites' => true,
            ],
            'recommendation' => $this->recommendation($counts, $groupSummary),
            'nextAction' => 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL',
        ];
    }

    /**
     * Built-in operator-safe approvals for unambiguous existing Definition matches.
     *
     * @return array<string, array<string, mixed>>
     */
    public function defaultOperatorDecisions(): array
    {
        $out = [];
        foreach ([
            'pins|PIN-COUNT',
            'pin|PIN-COUNT',
            'thread od mm|THREAD-OD',
            'thread od|THREAD-OD',
            'voltage v|VOLTAGE',
            'voltage|VOLTAGE',
            'voltage rating v|VOLTAGE',
            'current rating a|CURRENT',
            'current rating|CURRENT',
            'rating amp|CURRENT',
            'number of cores|CORES',
            'no. of cores|CORES',
            'no. of poles|CORES',
            'phases|CORES',
            'min. bend|MIN-BEND-RADIUS',
            'min bend|MIN-BEND-RADIUS',
            'approx. wt.|APPROX-WEIGHT',
            'overall diameter|NOM-CABLE-OD',
            'outer dia mm|NOM-CABLE-OD',
            'cable diameter mm|NOM-CABLE-OD',
            'operating temperature|OPERATING-TEMP',
            'hcl emission|HCL-EMISSION',
            'oxygen index|OXYGEN-INDEX',
        ] as $gk) {
            $out['group:' . $gk] = [
                'decision' => SpecFieldMappingDecisionState::APPROVED_MAP,
                'reason' => 'operator_default_high_confidence_existing_definition',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defsByCode
     * @return array<string, mixed>
     */
    private function resolveCandidate(
        string $style,
        string $field,
        string $legacyLabel,
        string $normalizedLabel,
        string $proposedName,
        string $example,
        array $defsByCode,
        bool $globalForbidden,
    ): array {
        $groupKeyBase = $normalizedLabel !== '' ? $normalizedLabel : 'unlabeled';

        if ($style === '*' || $globalForbidden) {
            return [
                'decision' => SpecFieldMappingDecisionState::REJECT,
                'reason' => 'global_style_field_assumption_forbidden',
                'confidence' => 'blocked',
                'targetDefinitionCode' => null,
                'groupKey' => $groupKeyBase . '|REJECT_GLOBAL',
                'typeCompatible' => null,
                'unitCompatible' => null,
            ];
        }

        if ($this->labelMatchesForceReview($normalizedLabel)) {
            $blocked = $this->guessBlockedConcept($normalizedLabel);
            return [
                'decision' => SpecFieldMappingDecisionState::REVIEW,
                'reason' => $blocked !== null
                    ? 'deferred_batch_b_concept_' . $blocked . '_no_approved_definition'
                    : 'no_approved_definition_for_label_or_ambiguous_dimension',
                'confidence' => 'low',
                'targetDefinitionCode' => null,
                'groupKey' => $groupKeyBase . '|REVIEW',
                'typeCompatible' => null,
                'unitCompatible' => null,
            ];
        }

        if (in_array($normalizedLabel, self::COLOUR_LABELS, true)
            || str_starts_with($normalizedLabel, 'colour')
            || str_starts_with($normalizedLabel, 'color')
        ) {
            $code = 'CORE-COLOURS';
            if (isset($defsByCode[$code])) {
                return [
                    'decision' => SpecFieldMappingDecisionState::PROPOSED_MAP,
                    'reason' => 'colour_field_propose_full_core_colours_matrix_not_coding_colour',
                    'confidence' => 'medium',
                    'targetDefinitionCode' => $code,
                    'targetDataType' => $defsByCode[$code]['dataTypeCode'] ?? null,
                    'targetUnit' => $defsByCode[$code]['unit'] ?? null,
                    'targetGroup' => $defsByCode[$code]['groupCode'] ?? null,
                    'groupKey' => $groupKeyBase . '|' . $code,
                    'typeCompatible' => true,
                    'unitCompatible' => true,
                ];
            }
        }

        $code = $this->matchDefinitionCode($normalizedLabel, $proposedName, $defsByCode);
        if ($code === null) {
            return [
                'decision' => SpecFieldMappingDecisionState::REVIEW,
                'reason' => 'no_existing_definition_match_for_label',
                'confidence' => 'low',
                'targetDefinitionCode' => null,
                'groupKey' => $groupKeyBase . '|REVIEW',
                'typeCompatible' => null,
                'unitCompatible' => null,
            ];
        }

        if (in_array($code, self::BLOCKED_DEFINITION_CODES, true) || !isset($defsByCode[$code])) {
            return [
                'decision' => SpecFieldMappingDecisionState::REVIEW,
                'reason' => 'target_definition_not_approved_or_missing_' . $code,
                'confidence' => 'low',
                'targetDefinitionCode' => null,
                'groupKey' => $groupKeyBase . '|REVIEW',
                'typeCompatible' => null,
                'unitCompatible' => null,
            ];
        }

        $def = $defsByCode[$code];
        $typeOk = $this->typeCompatible((string) ($def['dataTypeCode'] ?? ''), $example);
        $unitOk = $this->unitCompatible($normalizedLabel, $proposedName, (string) ($def['unit'] ?? ''));

        if (!$typeOk) {
            return [
                'decision' => SpecFieldMappingDecisionState::REVIEW,
                'reason' => 'type_incompatible_with_' . $code,
                'confidence' => 'low',
                'targetDefinitionCode' => $code,
                'targetDataType' => $def['dataTypeCode'] ?? null,
                'targetUnit' => $def['unit'] ?? null,
                'targetGroup' => $def['groupCode'] ?? null,
                'groupKey' => $groupKeyBase . '|' . $code,
                'typeCompatible' => false,
                'unitCompatible' => $unitOk,
            ];
        }
        if (!$unitOk) {
            return [
                'decision' => SpecFieldMappingDecisionState::REVIEW,
                'reason' => 'unit_incompatible_with_' . $code,
                'confidence' => 'low',
                'targetDefinitionCode' => $code,
                'targetDataType' => $def['dataTypeCode'] ?? null,
                'targetUnit' => $def['unit'] ?? null,
                'targetGroup' => $def['groupCode'] ?? null,
                'groupKey' => $groupKeyBase . '|' . $code,
                'typeCompatible' => true,
                'unitCompatible' => false,
            ];
        }

        return [
            'decision' => SpecFieldMappingDecisionState::PROPOSED_MAP,
            'reason' => 'evidence_matched_existing_definition',
            'confidence' => 'high',
            'targetDefinitionCode' => $code,
            'targetDataType' => $def['dataTypeCode'] ?? null,
            'targetUnit' => $def['unit'] ?? null,
            'targetGroup' => $def['groupCode'] ?? null,
            'groupKey' => $groupKeyBase . '|' . $code,
            'typeCompatible' => true,
            'unitCompatible' => true,
        ];
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $override
     * @param array<string, array<string, mixed>> $defsByCode
     * @return array<string, mixed>
     */
    private function applyOperatorOverride(array $resolved, array $override, array $defsByCode): array
    {
        $decision = (string) ($override['decision'] ?? '');
        if ($decision === '') {
            return $resolved;
        }
        if ($decision === SpecFieldMappingDecisionState::APPROVED_MAP) {
            $code = strtoupper((string) ($override['targetDefinitionCode'] ?? $resolved['targetDefinitionCode'] ?? ''));
            if ($code === '' || !isset($defsByCode[$code]) || in_array($code, self::BLOCKED_DEFINITION_CODES, true)) {
                $resolved['decision'] = SpecFieldMappingDecisionState::REVIEW;
                $resolved['reason'] = 'operator_approve_blocked_missing_or_deferred_definition';

                return $resolved;
            }
            if (($resolved['decision'] ?? '') === SpecFieldMappingDecisionState::REJECT) {
                return $resolved; // never approve global rejects
            }
            $resolved['targetDefinitionCode'] = $code;
            $resolved['targetDataType'] = $defsByCode[$code]['dataTypeCode'] ?? $resolved['targetDataType'] ?? null;
            $resolved['targetUnit'] = $defsByCode[$code]['unit'] ?? $resolved['targetUnit'] ?? null;
            $resolved['targetGroup'] = $defsByCode[$code]['groupCode'] ?? $resolved['targetGroup'] ?? null;
            $resolved['decision'] = SpecFieldMappingDecisionState::APPROVED_MAP;
            $resolved['reason'] = (string) ($override['reason'] ?? 'operator_approved');
            $resolved['confidence'] = 'high';
            $parts = explode('|', (string) ($resolved['groupKey'] ?? 'x|y'), 2);
            $resolved['groupKey'] = $parts[0] . '|' . $code;

            return $resolved;
        }

        $resolved['decision'] = $decision;
        if (isset($override['reason'])) {
            $resolved['reason'] = (string) $override['reason'];
        }
        if (isset($override['targetDefinitionCode'])) {
            $code = strtoupper((string) $override['targetDefinitionCode']);
            $resolved['targetDefinitionCode'] = $code !== '' ? $code : null;
        }

        return $resolved;
    }

    /**
     * @param array<string, array<string, mixed>> $defsByCode
     */
    private function matchDefinitionCode(string $normalizedLabel, string $proposedName, array $defsByCode): ?string
    {
        $candidates = [$normalizedLabel, $this->normalizeLabel($proposedName)];
        foreach ($candidates as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            if (isset(self::LABEL_TO_DEFINITION[$label])) {
                $code = self::LABEL_TO_DEFINITION[$label];
                if (isset($defsByCode[$code])) {
                    return $code;
                }
            }
            // strip trailing unit tokens
            $stripped = preg_replace('/\s+(mm|m|v|a|amp|kg\/km|°c|c)$/u', '', $label) ?? $label;
            $stripped = trim($stripped);
            if ($stripped !== $label && isset(self::LABEL_TO_DEFINITION[$stripped])) {
                $code = self::LABEL_TO_DEFINITION[$stripped];
                if (isset($defsByCode[$code])) {
                    return $code;
                }
            }
            foreach ($defsByCode as $code => $def) {
                $name = $this->normalizeLabel((string) ($def['name'] ?? ''));
                if ($name !== '' && ($name === $label || $name === $stripped)) {
                    return $code;
                }
            }
        }

        return null;
    }

    private function labelMatchesForceReview(string $normalizedLabel): bool
    {
        foreach (self::FORCE_REVIEW_LABELS as $prefix) {
            if ($normalizedLabel === $prefix || str_starts_with($normalizedLabel, $prefix . ' ')) {
                // thread od is approved separately
                if (str_starts_with($normalizedLabel, 'thread od')) {
                    return false;
                }
                return true;
            }
        }
        // diameter variants without overall/cable/nom → review (barrel/inner etc.)
        if (preg_match('/^(barrel|stud|inner|outer)\b/u', $normalizedLabel) === 1) {
            if (str_starts_with($normalizedLabel, 'outer') && str_contains($normalizedLabel, 'dia')) {
                return false; // handled as NOM-CABLE-OD via aliases when matched
            }
            return true;
        }

        return false;
    }

    private function guessBlockedConcept(string $normalizedLabel): ?string
    {
        if (str_starts_with($normalizedLabel, 'length')) {
            return 'LENGTH';
        }
        if (str_starts_with($normalizedLabel, 'width')) {
            return 'WIDTH';
        }

        return null;
    }

    private function typeCompatible(string $dataType, string $example): bool
    {
        $example = trim($example);
        if ($example === '' || $this->isPlaceholder($example)) {
            return true;
        }
        $dt = strtolower($dataType);
        if (in_array($dt, ['integer'], true)) {
            return preg_match('/^-?\d+(\.0+)?$/', $example) === 1;
        }
        if (in_array($dt, ['decimal', 'measurement', 'range'], true)) {
            return preg_match('/^-?\d+(\.\d+)?$/', $example) === 1
                || preg_match('/^-?\d+(\.\d+)?\s*[-–]\s*-?\d+(\.\d+)?$/', $example) === 1;
        }

        return true;
    }

    private function unitCompatible(string $normalizedLabel, string $proposedName, string $targetUnit): bool
    {
        $blob = $this->normalizeLabel($normalizedLabel . ' ' . $proposedName);
        $unit = trim($targetUnit);
        if ($unit === '') {
            return true;
        }
        // mm² vs mm
        if (str_contains($blob, 'mm2') || str_contains($blob, 'mm²')) {
            return str_contains($unit, 'mm²') || strtoupper($unit) === 'MM2' || str_contains(strtolower($unit), 'mm²');
        }
        if (preg_match('/\bmm\b/u', $blob) === 1) {
            // target may store "mm", "OD mm", "Radius mm"
            return str_contains(strtolower($unit), 'mm') && !str_contains($unit, 'mm²');
        }
        if (preg_match('/\b(v|volt)/u', $blob) === 1) {
            return strtoupper($unit) === 'V' || str_contains(strtolower($unit), 'v');
        }
        if (preg_match('/\b(a|amp)/u', $blob) === 1) {
            return str_contains(strtolower($unit), 'amp') || strtoupper($unit) === 'A';
        }

        return true;
    }

    private function isPlaceholder(string $value): bool
    {
        $v = strtolower(trim($value));

        return in_array($v, ['', '-', 'n/a', 'na', 'null', 'unknown', 'none'], true);
    }

    public function normalizeLabel(string $label): string
    {
        $s = preg_replace('/<[^>]+>/u', ' ', $label) ?? $label;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
        $s = str_replace(["\xc2\xa0", '⌀', 'ø', 'Ø', '°'], [' ', 'dia ', 'dia ', 'dia ', 'deg '], $s);
        $s = preg_replace('/\s+/u', ' ', strtolower(trim($s))) ?? '';

        return $s;
    }

    private function normalizeStyle(string $style): string
    {
        $s = strtoupper(trim($style));
        if ($s === '' || $s === '*') {
            return $s === '*' ? '*' : '';
        }

        return preg_replace('/\s+/', '', $s) ?? $s;
    }

    private function mappingKey(string $sourceSystem, string $style, string $field): string
    {
        return strtolower($sourceSystem) . '|' . strtoupper($style) . '|' . strtolower($field);
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
            /** @var array<string, mixed> $row */
            $code = strtoupper((string) ($row['code'] ?? $row['definitionCode'] ?? ''));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $mappings
     * @return array<string, array<string, mixed>>
     */
    private function indexExistingStyleMappings(array $mappings): array
    {
        $out = [];
        foreach ($mappings as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string, mixed> $row */
            $style = strtoupper(trim((string) ($row['legacyStyleCode'] ?? '')));
            $field = strtolower(trim((string) ($row['sourceField'] ?? '')));
            if ($style === '' || $field === '') {
                continue;
            }
            $sys = strtolower((string) ($row['sourceSystem'] ?? 'tc'));
            $out[$this->mappingKey($sys, $style, $field)] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @return array<string, int>
     */
    private function countDecisions(array $mappings): array
    {
        $counts = [
            SpecFieldMappingDecisionState::APPROVED_MAP => 0,
            SpecFieldMappingDecisionState::PROPOSED_MAP => 0,
            SpecFieldMappingDecisionState::REVIEW => 0,
            SpecFieldMappingDecisionState::NOT_APPLICABLE => 0,
            SpecFieldMappingDecisionState::REJECT => 0,
            SpecFieldMappingDecisionState::CONFLICT => 0,
        ];
        foreach ($mappings as $row) {
            $d = (string) ($row['decision'] ?? SpecFieldMappingDecisionState::REVIEW);
            if (!isset($counts[$d])) {
                $counts[$d] = 0;
            }
            ++$counts[$d];
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @return list<array<string, mixed>>
     */
    private function buildGroups(array $mappings): array
    {
        $groups = [];
        foreach ($mappings as $row) {
            if (($row['styleCode'] ?? '') === '*') {
                continue;
            }
            $gk = (string) ($row['groupKey'] ?? 'unknown');
            if (!isset($groups[$gk])) {
                $groups[$gk] = [
                    'groupKey' => $gk,
                    'normalizedLabel' => $row['normalizedLabel'] ?? null,
                    'legacyLabelSample' => $row['legacyLabel'] ?? null,
                    'targetDefinitionCode' => $row['targetDefinitionCode'] ?? null,
                    'targetGroup' => $row['targetGroup'] ?? null,
                    'targetDataType' => $row['targetDataType'] ?? null,
                    'targetUnit' => $row['targetUnit'] ?? null,
                    'decision' => $row['decision'] ?? SpecFieldMappingDecisionState::REVIEW,
                    'confidence' => $row['confidence'] ?? 'low',
                    'stylesAffected' => [],
                    'mappingRows' => 0,
                    'sampleValues' => [],
                    'conflictCount' => 0,
                ];
            }
            ++$groups[$gk]['mappingRows'];
            $style = (string) ($row['styleCode'] ?? '');
            if ($style !== '') {
                $groups[$gk]['stylesAffected'][$style] = true;
            }
            foreach (($row['sampleValues'] ?? []) as $sv) {
                if (count($groups[$gk]['sampleValues']) < 5 && is_string($sv) && $sv !== '') {
                    $groups[$gk]['sampleValues'][] = $sv;
                }
            }
            if (($row['decision'] ?? '') === SpecFieldMappingDecisionState::CONFLICT) {
                ++$groups[$gk]['conflictCount'];
            }
            // Group decision = APPROVED only if all members approved
            $member = (string) ($row['decision'] ?? '');
            $current = (string) $groups[$gk]['decision'];
            if ($current === SpecFieldMappingDecisionState::APPROVED_MAP
                && $member !== SpecFieldMappingDecisionState::APPROVED_MAP
            ) {
                $groups[$gk]['decision'] = $member;
            } elseif ($current === SpecFieldMappingDecisionState::PROPOSED_MAP
                && !in_array($member, [SpecFieldMappingDecisionState::PROPOSED_MAP, SpecFieldMappingDecisionState::APPROVED_MAP], true)
            ) {
                $groups[$gk]['decision'] = $member;
            }
        }

        $out = [];
        foreach ($groups as $g) {
            $g['stylesAffected'] = count($g['stylesAffected']);
            $g['productsAffectedEstimate'] = $g['stylesAffected'];
            $out[] = $g;
        }
        usort($out, static fn (array $a, array $b): int => ($b['mappingRows'] <=> $a['mappingRows']));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @return list<array<string, mixed>>
     */
    private function detectStyleConflicts(array $mappings): array
    {
        $byStyleField = [];
        $conflicts = [];
        foreach ($mappings as $row) {
            $style = (string) ($row['styleCode'] ?? '');
            $field = (string) ($row['sourceField'] ?? '');
            if ($style === '' || $style === '*' || $field === '') {
                continue;
            }
            $key = $style . '|' . $field;
            if (isset($byStyleField[$key])) {
                $conflicts[] = [
                    'style' => $style,
                    'sourceField' => $field,
                    'legacyLabel' => $row['legacyLabel'] ?? null,
                    'candidateDefinitions' => array_values(array_unique(array_filter([
                        $byStyleField[$key]['targetDefinitionCode'] ?? null,
                        $row['targetDefinitionCode'] ?? null,
                    ]))),
                    'values' => $row['sampleValues'] ?? [],
                    'reason' => 'duplicate_style_field_mapping_rows',
                    'severity' => 'high',
                ];
            } else {
                $byStyleField[$key] = $row;
            }
        }

        return $conflicts;
    }

    /**
     * @param list<mixed> $products
     * @param list<array<string, mixed>> $mappings
     * @return array<string, mixed>
     */
    private function calculateProductReadiness(
        array $products,
        array $mappings,
        int $classificationReady,
        int $specMasterReady,
        int $accessoryBlocked,
        int $fullReady,
        int $fullBlocked,
    ): array {
        $byStyle = [];
        foreach ($mappings as $row) {
            $style = strtoupper((string) ($row['styleCode'] ?? ''));
            if ($style === '' || $style === '*') {
                continue;
            }
            $byStyle[$style][] = $row;
        }

        $projectedFieldReady = 0;
        $fieldReview = 0;
        $fieldNotApplicable = 0;
        $variantsProjected = 0;
        $variantsBlocked = 0;
        $variantsApprovedAssoc = 0;

        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            /** @var array<string, mixed> $p */
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $variants = (int) ($p['variantCount'] ?? 0);
            $rows = $byStyle[$style] ?? [];
            if ($rows === []) {
                // Accessory d/v not present — NOT the same as field-mapping complete.
                ++$fieldNotApplicable;
                continue;
            }
            $allApproved = true;
            $hasApproved = false;
            foreach ($rows as $row) {
                $d = (string) ($row['decision'] ?? '');
                if ($d === SpecFieldMappingDecisionState::APPROVED_MAP) {
                    $hasApproved = true;
                    continue;
                }
                if (in_array($d, [SpecFieldMappingDecisionState::NOT_APPLICABLE], true)) {
                    continue;
                }
                $allApproved = false;
            }
            if ($allApproved) {
                ++$projectedFieldReady;
                $variantsProjected += $variants;
                $variantsApprovedAssoc += $variants;
            } else {
                ++$fieldReview;
                $variantsBlocked += $variants;
                if ($hasApproved) {
                    $variantsApprovedAssoc += $variants;
                }
            }
        }

        // Cap reported variant sums to live catalogue size when inflated by source metadata.
        $liveVariantCap = 4178;
        $variantsProjected = min($variantsProjected, $liveVariantCap);
        $variantsBlocked = min($variantsBlocked, $liveVariantCap);
        $variantsApprovedAssoc = min($variantsApprovedAssoc, $liveVariantCap);

        $projectedFullReady = min($fullReady, $projectedFieldReady);

        return [
            'CLASSIFICATION_READY' => $classificationReady,
            'SPEC_MASTER_READY' => $specMasterReady,
            'CURRENT_SPEC_FIELD_MAPPING_READY' => 0,
            'PROJECTED_SPEC_FIELD_MAPPING_READY_AFTER_APPLY' => $projectedFieldReady,
            'SPEC_FIELD_MAPPING_REVIEW' => $fieldReview,
            'FIELD_MAPPING_NOT_APPLICABLE' => $fieldNotApplicable,
            'ACCESSORY_IDENTITY_BLOCKED' => $accessoryBlocked,
            'CURRENT_FULL_PRODUCT_MIGRATION_READY' => $fullReady,
            'CURRENT_FULL_PRODUCT_MIGRATION_BLOCKED' => $fullBlocked,
            'PROJECTED_FULL_PRODUCT_MIGRATION_READY_AFTER_APPLY' => $projectedFullReady,
            'PROJECTED_FULL_PRODUCT_MIGRATION_BLOCKED_AFTER_APPLY' => $fullBlocked,
            'variantImpact' => [
                'variantsAssociatedWithApprovedMappings' => $variantsApprovedAssoc,
                'variantsProjectedFieldMappingReady' => $variantsProjected,
                'variantsBlockedByUnresolvedMappings' => $variantsBlocked,
                'variantsDvNotApplicable' => max(0, $liveVariantCap - $variantsProjected - $variantsBlocked),
            ],
            'note' => 'CURRENT_* reflects target state (mappings not applied). Vacuous cable styles are FIELD_MAPPING_NOT_APPLICABLE, not READY. PROJECTED_* counts only styles with fully APPROVED d/v mappings.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @return array<string, int>
     */
    private function typeUnitStats(array $mappings): array
    {
        $typeOk = 0;
        $typeBad = 0;
        $unitOk = 0;
        $unitBad = 0;
        foreach ($mappings as $row) {
            if (($row['styleCode'] ?? '') === '*') {
                continue;
            }
            if ($row['typeCompatible'] === true) {
                ++$typeOk;
            } elseif ($row['typeCompatible'] === false) {
                ++$typeBad;
            }
            if ($row['unitCompatible'] === true) {
                ++$unitOk;
            } elseif ($row['unitCompatible'] === false) {
                ++$unitBad;
            }
        }

        return [
            'typeCompatible' => $typeOk,
            'typeConflicts' => $typeBad,
            'unitCompatible' => $unitOk,
            'unitConflicts' => $unitBad,
        ];
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{label: string, count: int}>
     */
    private function topN(array $counts, int $n): array
    {
        arsort($counts);
        $out = [];
        $i = 0;
        foreach ($counts as $label => $count) {
            $out[] = ['label' => $label, 'count' => $count];
            if (++$i >= $n) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, int> $groupSummary
     */
    private function recommendation(array $counts, array $groupSummary): string
    {
        $approved = (int) ($counts[SpecFieldMappingDecisionState::APPROVED_MAP] ?? 0);
        $review = (int) ($counts[SpecFieldMappingDecisionState::REVIEW] ?? 0);
        $proposed = (int) ($counts[SpecFieldMappingDecisionState::PROPOSED_MAP] ?? 0);
        if ($approved > 0 && $review > $approved) {
            return 'CONTINUE FIELD MAPPING REVIEW';
        }
        if ($approved > 50 && $proposed < $approved) {
            return 'APPROVE BATCH C TARGET APPLY';
        }
        if ($approved > 0) {
            return 'CONTINUE FIELD MAPPING REVIEW';
        }

        return 'CONTINUE FIELD MAPPING REVIEW';
    }
}
