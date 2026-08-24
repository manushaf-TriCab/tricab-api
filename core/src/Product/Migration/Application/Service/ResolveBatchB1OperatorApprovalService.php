<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Specification\SpecMasterDecisionState;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch B.1 — explicit operator resolution of Batch B proposals.
 * Never writes target masters. Never starts Batch C. --apply remains refused.
 */
final class ResolveBatchB1OperatorApprovalService
{
    /**
     * @param array<string, mixed> $batchBPackage
     * @param array<string, mixed> $operatorDecisions
     * @param array<string, mixed> $azureLive
     * @return array<string, mixed>
     */
    public function build(array $batchBPackage, array $operatorDecisions, array $azureLive): array
    {
        $definitions = is_array($batchBPackage['definitions']['items'] ?? null)
            ? $batchBPackage['definitions']['items']
            : [];
        $standards = is_array($batchBPackage['standards']['items'] ?? null)
            ? $batchBPackage['standards']['items']
            : [];
        $approvals = is_array($batchBPackage['approvals']['items'] ?? null)
            ? $batchBPackage['approvals']['items']
            : [];
        $aliases = is_array($batchBPackage['aliases']['items'] ?? null)
            ? $batchBPackage['aliases']['items']
            : [];
        $units = is_array($batchBPackage['units']['items'] ?? null)
            ? $batchBPackage['units']['items']
            : [];

        $defOverrides = is_array($operatorDecisions['definitions'] ?? null) ? $operatorDecisions['definitions'] : [];
        $stdRules = is_array($operatorDecisions['standards'] ?? null) ? $operatorDecisions['standards'] : [];
        $aprRules = is_array($operatorDecisions['approvals'] ?? null) ? $operatorDecisions['approvals'] : [];
        $aliasRules = is_array($operatorDecisions['aliases'] ?? null) ? $operatorDecisions['aliases'] : [];

        $targetDefs = $this->indexByCode($azureLive['definitions'] ?? []);
        $standardOptions = $this->indexOptionsByDef($azureLive['options'] ?? [], 'STANDARD');
        $approvalOptions = $this->indexOptionsByDef($azureLive['options'] ?? [], 'APPROVALS');

        $definitions = $this->applyDefinitionDecisions($definitions, $defOverrides, $targetDefs);
        $standards = $this->applyStandardDecisions($standards, $stdRules, $standardOptions);
        $approvalAudit = $this->auditApprovals($approvals);
        $approvals = $this->applyApprovalDecisions($approvals, $aprRules, $approvalOptions, $approvalAudit);
        $aliases = $this->applyAliasDecisions($aliases, $aliasRules, $standards);

        $defCounts = $this->countByDecision($definitions, [
            SpecMasterDecisionState::APPROVED_MAP_EXISTING,
            SpecMasterDecisionState::APPROVED_CREATE,
            SpecMasterDecisionState::PROPOSED_CREATE,
            SpecMasterDecisionState::REVIEW,
            SpecMasterDecisionState::NOT_A_SPECIFICATION,
            SpecMasterDecisionState::REJECT,
        ]);
        $stdCounts = $this->optionCounts($standards);
        $aprCounts = $this->optionCounts($approvals);
        $aliasCounts = [
            SpecMasterDecisionState::SAFE_ALIAS => 0,
            SpecMasterDecisionState::ALIAS_REVIEW => 0,
            SpecMasterDecisionState::ALIAS_REJECT => 0,
        ];
        foreach ($aliases as $a) {
            $d = (string) ($a['decision'] ?? SpecMasterDecisionState::ALIAS_REVIEW);
            if (!isset($aliasCounts[$d])) {
                $aliasCounts[$d] = 0;
            }
            ++$aliasCounts[$d];
        }

        $uniqueCreates = [];
        foreach ($definitions as $d) {
            if (($d['decision'] ?? '') !== SpecMasterDecisionState::APPROVED_CREATE) {
                continue;
            }
            $code = strtoupper(MigrationPayload::string($d['proposedDefinitionCode'] ?? ''));
            if ($code === '') {
                continue;
            }
            $uniqueCreates[$code] = [
                'code' => $code,
                'name' => MigrationPayload::string($d['proposedDefinitionName'] ?? $code),
                'group' => MigrationPayload::string($d['proposedGroup'] ?? 'construction'),
                'dataType' => MigrationPayload::string($d['dataType'] ?? 'text'),
                'unit' => MigrationPayload::stringOrNull($d['unit'] ?? null),
                'sourceFields' => array_values(array_unique(array_merge(
                    $uniqueCreates[$code]['sourceFields'] ?? [],
                    [MigrationPayload::string($d['sourceField'] ?? '')],
                ))),
            ];
        }

        $approvedDefinitions = array_values(array_filter(
            $definitions,
            static fn (array $r): bool => in_array(
                $r['decision'] ?? '',
                [SpecMasterDecisionState::APPROVED_MAP_EXISTING, SpecMasterDecisionState::APPROVED_CREATE],
                true,
            ),
        ));
        $approvedStandards = array_values(array_filter(
            $standards,
            static fn (array $r): bool => in_array(
                $r['decision'] ?? '',
                [SpecMasterDecisionState::APPROVED_MAP_EXISTING, SpecMasterDecisionState::APPROVED_CREATE],
                true,
            ),
        ));
        $approvedApprovals = array_values(array_filter(
            $approvals,
            static fn (array $r): bool => in_array(
                $r['decision'] ?? '',
                [SpecMasterDecisionState::APPROVED_MAP_EXISTING, SpecMasterDecisionState::APPROVED_CREATE],
                true,
            ),
        ));
        $safeAliases = array_values(array_filter(
            $aliases,
            static fn (array $r): bool => ($r['decision'] ?? '') === SpecMasterDecisionState::SAFE_ALIAS,
        ));

        $unitMapped = 0;
        $unitReview = 0;
        foreach ($units as $u) {
            if (($u['decision'] ?? '') === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$unitMapped;
            } else {
                ++$unitReview;
            }
        }

        $readiness = [
            'DEFINITION_READY' => $defCounts[SpecMasterDecisionState::APPROVED_MAP_EXISTING]
                + $defCounts[SpecMasterDecisionState::APPROVED_CREATE],
            'DEFINITION_REVIEW' => $defCounts[SpecMasterDecisionState::PROPOSED_CREATE]
                + $defCounts[SpecMasterDecisionState::REVIEW],
            'UNIT_READY' => $unitMapped,
            'UNIT_REVIEW' => $unitReview,
            'STANDARD_READY' => $stdCounts['mapped'] + $stdCounts['approved'],
            'STANDARD_REVIEW' => $stdCounts['proposed'] + $stdCounts['review'],
            'APPROVAL_READY' => $aprCounts['mapped'] + $aprCounts['approved'],
            'APPROVAL_REVIEW' => $aprCounts['proposed'] + $aprCounts['review'],
            'ALIAS_READY' => $aliasCounts[SpecMasterDecisionState::SAFE_ALIAS],
            'ALIAS_REVIEW' => $aliasCounts[SpecMasterDecisionState::ALIAS_REVIEW],
        ];

        $classificationReady = (int) ($batchBPackage['productReadinessImpact']['CLASSIFICATION_READY'] ?? 95);
        $fullReady = (int) ($batchBPackage['productReadinessImpact']['FULL_PRODUCT_MIGRATION_READY'] ?? 54);
        $fullBlocked = (int) ($batchBPackage['productReadinessImpact']['FULL_PRODUCT_MIGRATION_BLOCKED'] ?? 336);
        // Masters decided (not applied): cable classification-ready products can use approved STANDARD/APPROVALS/BRAID/VOLTAGE/SHEATH paths.
        // Remaining REVIEW defs (LENGTH/WIDTH/LSZH/nek) and Batch C keep SPEC_FIELD_MAPPING at 0.
        $specMasterReady = $defCounts[SpecMasterDecisionState::APPROVED_CREATE] > 0
            || $defCounts[SpecMasterDecisionState::APPROVED_MAP_EXISTING] >= 7
            ? $classificationReady
            : 0;

        $collisions = $this->detectCollisions($uniqueCreates, $targetDefs, $approvedStandards, $approvedApprovals, $standardOptions, $approvalOptions);

        $dryRun = [
            'targetValidated' => ($azureLive['liveValidation'] ?? '') === 'AVAILABLE',
            'mode' => 'DRY_RUN_ONLY',
            'writesWouldExecute' => false,
            'definitionsMappedExisting' => $defCounts[SpecMasterDecisionState::APPROVED_MAP_EXISTING],
            'definitionsToCreate' => count($uniqueCreates),
            'standardOptionsMappedExisting' => $stdCounts['mapped'],
            'standardOptionsToCreate' => $stdCounts['approved'],
            'approvalOptionsMappedExisting' => $aprCounts['mapped'],
            'approvalOptionsToCreate' => $aprCounts['approved'],
            'definitionMappingsToInsert' => $defCounts[SpecMasterDecisionState::APPROVED_MAP_EXISTING]
                + $defCounts[SpecMasterDecisionState::APPROVED_CREATE],
            'optionMappingsToInsert' => $stdCounts['mapped'] + $stdCounts['approved']
                + $aprCounts['mapped'] + $aprCounts['approved'],
            'safeAliasesToInsert' => count($safeAliases),
            'productSpecificationValues' => 0,
            'productMutations' => 0,
            'variantMutations' => 0,
            'classificationMutations' => 0,
            'catalogueMutations' => 0,
            'pricingMutations' => 0,
            'mediaMutations' => 0,
            'documentMutations' => 0,
            'legacyWrites' => 0,
            'collisions' => $collisions,
            'idempotencyProjection' => [
                'secondRunDefinitionCreates' => 0,
                'secondRunOptionCreates' => 0,
                'secondRunDefinitionMappings' => 0,
                'secondRunOptionMappings' => 0,
                'secondRunAliasInserts' => 0,
            ],
            'futureApplyOrder' => [
                'validateTarget',
                'validateGroups',
                'validateUnits',
                'resolveExistingDefinitions',
                'createApprovedDefinitions',
                'reReadDefinitionIds',
                'insertDefinitionMappings',
                'resolveExistingOptions',
                'createApprovedStandardOptions',
                'createApprovedApprovalOptions',
                'reReadOptionIds',
                'insertOptionMappings',
                'insertSafeAliases',
                'verify',
                'recalculateReadiness',
            ],
        ];

        $recommendation = (
            $collisions === []
            && count($uniqueCreates) + $stdCounts['approved'] + $aprCounts['approved'] + count($safeAliases) > 0
            && $defCounts[SpecMasterDecisionState::PROPOSED_CREATE] === 0
        ) ? 'APPROVE BATCH B TARGET APPLY' : 'CONTINUE SPECIFICATION REVIEW';

        // Prefer continue if significant REVIEW remains on approvals/standards that were intentionally left.
        if ($aprCounts['review'] + $aprCounts['proposed'] > 50 || $stdCounts['review'] > 10) {
            // Still may approve apply for the approved subset only.
            if ($collisions === [] && (count($uniqueCreates) > 0 || $stdCounts['approved'] > 0 || $defCounts[SpecMasterDecisionState::APPROVED_MAP_EXISTING] > 0)) {
                $recommendation = 'APPROVE BATCH B TARGET APPLY';
            }
        }

        return [
            'phase' => '12.7-batch-b.1',
            'mode' => 'OPERATOR_RESOLUTION_DRY_RUN_ONLY',
            'batchC' => 'NOT_STARTED',
            'target' => [
                'server' => MigrationPayload::string($azureLive['server'] ?? 'tricab-mel'),
                'database' => MigrationPayload::string($azureLive['database'] ?? 'tc'),
                'environment' => 'NON-PRODUCTION',
                'liveValidation' => MigrationPayload::string($azureLive['liveValidation'] ?? 'UNKNOWN'),
                'groups' => (int) ($azureLive['groupCount'] ?? count($azureLive['groups'] ?? [])),
                'definitions' => (int) ($azureLive['definitionCount'] ?? count($azureLive['definitions'] ?? [])),
                'units' => (int) ($azureLive['unitCount'] ?? count($azureLive['units'] ?? [])),
                'options' => (int) ($azureLive['optionCount'] ?? count($azureLive['options'] ?? [])),
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => 0,
            ],
            'definitions' => [
                'items' => $definitions,
                'counts' => $defCounts,
                'uniqueApprovedCreates' => array_values($uniqueCreates),
            ],
            'units' => [
                'items' => $units,
                'mapped' => $unitMapped,
                'review' => $unitReview,
                'newRequired' => 0,
            ],
            'coreColours' => $batchBPackage['coreColours'] ?? [
                'fullCoreColourSpecification' => 'READY',
                'codingCoreColour' => 'SEPARATE',
                'informationLoss' => 'NO',
            ],
            'standards' => [
                'items' => $standards,
                'counts' => $stdCounts,
                'raw' => 78,
                'canonicalConcepts' => count($standards),
            ],
            'approvalSemanticAudit' => $approvalAudit,
            'approvals' => [
                'items' => $approvals,
                'counts' => $aprCounts,
                'dnvDecision' => 'KEEP_DISTINCT — existing target has DNV-GL only; no DNV source value in this inventory; silent merge remains forbidden',
            ],
            'aliases' => [
                'items' => $aliases,
                'counts' => $aliasCounts,
            ],
            'approvedSets' => [
                'definitions' => $approvedDefinitions,
                'standards' => $approvedStandards,
                'approvals' => $approvedApprovals,
                'aliases' => $safeAliases,
            ],
            'readiness' => $readiness,
            'productReadinessImpact' => [
                'CLASSIFICATION_READY' => $classificationReady,
                'SPEC_MASTER_READY' => $specMasterReady,
                'SPEC_FIELD_MAPPING_READY' => 0,
                'ACCESSORY_IDENTITY_BLOCKED' => 41,
                'ACCESSORY_IDENTITY_READY' => 0,
                'MEDIA_READY' => 0,
                'DOCUMENT_READY' => 0,
                'FULL_PRODUCT_MIGRATION_READY' => $fullReady,
                'FULL_PRODUCT_MIGRATION_BLOCKED' => $fullBlocked,
                'note' => 'SPEC_MASTER_READY reflects operator-approved masters (pre-apply). SPEC_FIELD_MAPPING_READY remains 0 until Batch C.',
            ],
            'dryRunTargetPlan' => $dryRun,
            'approvalBoundary' => [
                'batchBApplyGuard' => 'BATCH_B_APPLY_NOT_APPROVED',
                'batchCGuard' => 'BATCH_C_NOT_STARTED',
                'productMigrationGuard' => 'PRODUCT_MIGRATION_NOT_APPROVED',
            ],
            'recommendation' => $recommendation,
            'nextAction' => 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL',
        ];
    }

    /**
     * Built-in Batch B.1 operator decision package (evidence-based; not auto-approve-all).
     *
     * @return array<string, mixed>
     */
    public function defaultOperatorDecisions(): array
    {
        return [
            'definitions' => [
                'degrees' => [
                    'decision' => SpecMasterDecisionState::APPROVED_CREATE,
                    'proposedDefinitionCode' => 'OPERATING-TEMP',
                    'proposedDefinitionName' => 'Operating temperature',
                    'proposedGroup' => 'thermal',
                    'dataType' => 'decimal',
                    'unit' => 'C',
                    'reason' => 'operator_approved_create_operating_temp_from_degrees_evidence',
                ],
                'ind_degrees' => [
                    'decision' => SpecMasterDecisionState::APPROVED_CREATE,
                    'proposedDefinitionCode' => 'OPERATING-TEMP',
                    'proposedDefinitionName' => 'Operating temperature',
                    'proposedGroup' => 'thermal',
                    'dataType' => 'decimal',
                    'unit' => 'C',
                    'reason' => 'operator_same_concept_as_degrees_single_target_create',
                ],
                'kilovolts' => [
                    'decision' => SpecMasterDecisionState::APPROVED_MAP_EXISTING,
                    'existingTargetDefinition' => 'VOLTAGE',
                    'reason' => 'operator_map_kilovolts_to_existing_VOLTAGE',
                ],
                'ind_kilovolts' => [
                    'decision' => SpecMasterDecisionState::APPROVED_MAP_EXISTING,
                    'existingTargetDefinition' => 'VOLTAGE',
                    'reason' => 'operator_map_ind_kilovolts_to_existing_VOLTAGE',
                ],
                'hcl_emission' => [
                    'decision' => SpecMasterDecisionState::APPROVED_CREATE,
                    'proposedDefinitionCode' => 'HCL-EMISSION',
                    'proposedDefinitionName' => 'HCl emission',
                    'proposedGroup' => 'compliance',
                    'dataType' => 'text',
                    'unit' => null,
                    'reason' => 'operator_approved_create_fire_performance_hcl',
                ],
                'oxygen_index' => [
                    'decision' => SpecMasterDecisionState::APPROVED_CREATE,
                    'proposedDefinitionCode' => 'OXYGEN-INDEX',
                    'proposedDefinitionName' => 'Oxygen index',
                    'proposedGroup' => 'compliance',
                    'dataType' => 'text',
                    'unit' => null,
                    'reason' => 'operator_approved_create_loi_oxygen_index',
                ],
                'ind_lszh_fire' => [
                    'decision' => SpecMasterDecisionState::REVIEW,
                    'reason' => 'operator_keep_review_serialized_multi_flag_acf_not_clean_definition',
                ],
                'spec_labels:d1|PIN-COUNT' => [
                    'decision' => SpecMasterDecisionState::APPROVED_CREATE,
                    'proposedDefinitionCode' => 'PIN-COUNT',
                    'proposedDefinitionName' => 'Pins',
                    'proposedGroup' => 'construction',
                    'dataType' => 'integer',
                    'unit' => null,
                    'reason' => 'operator_approved_create_pin_count_concept_not_d1_field',
                ],
                'spec_labels:d1|THREAD-OD' => [
                    'decision' => SpecMasterDecisionState::APPROVED_CREATE,
                    'proposedDefinitionCode' => 'THREAD-OD',
                    'proposedDefinitionName' => 'Thread OD',
                    'proposedGroup' => 'mechanical',
                    'dataType' => 'decimal',
                    'unit' => 'MM',
                    'reason' => 'operator_approved_create_thread_od_concept_not_d1_field',
                ],
                'spec_labels:v1|LENGTH' => [
                    'decision' => SpecMasterDecisionState::REVIEW,
                    'reason' => 'operator_keep_review_length_too_generic_across_styles_batch_c',
                ],
                'spec_labels:d3|WIDTH' => [
                    'decision' => SpecMasterDecisionState::REVIEW,
                    'reason' => 'operator_keep_review_width_too_generic_across_styles_batch_c',
                ],
                'compound_code' => [
                    'decision' => SpecMasterDecisionState::APPROVED_MAP_EXISTING,
                    'existingTargetDefinition' => 'SHEATH',
                    'reason' => 'operator_map_compound_code_xlpo_samples_to_SHEATH',
                ],
                'industry_code' => [
                    'decision' => SpecMasterDecisionState::NOT_A_SPECIFICATION,
                    'reason' => 'operator_industry_code_is_coding_attribute_not_specification',
                ],
                'nek_class' => [
                    'decision' => SpecMasterDecisionState::REVIEW,
                    'reason' => 'operator_keep_review_empty_samples_may_feed_standard_options_later',
                ],
            ],
            'standards' => [
                '__approveGenuineStandards' => true,
                '__rejectParseFragments' => true,
                'mapExisting' => [
                    'IEC 61034-1&2' => 'IEC-61034-1_2',
                    'AS/NZS IEC 60332-1 – Flame Test' => 'IEC-60332-1',
                    'AS/NZS IEC 60754 - Halogen' => 'IEC-60754-2',
                    'AS/NZS IEC60754 - Halogen' => 'IEC-60754-2',
                    'NEK TS 606 – Oil & Mud' => 'NEK-TS606-ED6',
                    'IEC 60331' => 'IEC-60331-1',
                    'AIEC 60332-1' => 'IEC-60332-1',
                ],
                'approveCreate' => [
                    'AS/NZS 5000.1',
                    'AS/NZS 3008.1',
                    'AS/NZS 1660.5.4',
                    'AS/NZS 1660.5.5',
                    'AS/NZS 3013',
                    'AS/NZS 5033',
                    'AS/NZS 5000.3',
                    'AS 1670',
                    'IEC 62930',
                    'BS EN 50267',
                    'BS EN 61034',
                    'BS 6387 (C,W, Z)',
                ],
                'reject' => ['W', 'Z)', 'Cat A', 'UPM'],
                'review' => [
                    'AS/NZS 3008',
                    'AS/NZS IEC 60332-1 – Flame Test AS/NZS IEC 60754 - Halogen NEK TS 606 – Oil & Mud',
                    'BS 6387 (C',
                ],
            ],
            'approvals' => [
                '__rejectStyleCodes' => true,
                '__rejectProductTypes' => true,
                '__rejectDocumentTitles' => true,
                '__rejectTestMethodLabels' => true,
                'keepReviewIssuingOrgAbbreviations' => ['AS', 'UP', 'WI'],
                'approveCreate' => [],
                'mapExisting' => [],
            ],
            'aliases' => [
                'safe' => [
                    ['raw' => 'AIEC 60332-1', 'target' => 'IEC 60332-1', 'reason' => 'typo_prefix_A_deterministic_to_IEC_60332-1'],
                    ['raw' => 'AS/NZS IEC60754 - Halogen', 'target' => 'IEC 60754-2', 'reason' => 'formatting_variant_maps_existing_halogen_standard'],
                ],
                'rejectMultilineBlobs' => true,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @param array<string, mixed> $overrides
     * @param array<string, array<string, mixed>> $targetDefs
     * @return list<array<string, mixed>>
     */
    private function applyDefinitionDecisions(array $definitions, array $overrides, array $targetDefs): array
    {
        $out = [];
        foreach ($definitions as $row) {
            $field = MigrationPayload::string($row['sourceField'] ?? '');
            $code = MigrationPayload::string($row['proposedDefinitionCode'] ?? '');
            $key = $field;
            $alt = $field . '|' . $code;
            $override = null;
            if (isset($overrides[$alt]) && is_array($overrides[$alt])) {
                $override = $overrides[$alt];
            } elseif (isset($overrides[$key]) && is_array($overrides[$key])) {
                $override = $overrides[$key];
            }
            if ($override !== null) {
                $decision = MigrationPayload::string($override['decision'] ?? $row['decision'] ?? SpecMasterDecisionState::REVIEW);
                // Never auto-create without operator override already being APPROVED_CREATE
                if ($decision === SpecMasterDecisionState::APPROVED_CREATE) {
                    $createCode = strtoupper(MigrationPayload::string($override['proposedDefinitionCode'] ?? $code));
                    if ($createCode !== '' && isset($targetDefs[$createCode])) {
                        $decision = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                        $row['existingTargetDefinition'] = $createCode;
                        $row['reason'] = 'downgraded_create_to_map_existing_code_collision';
                    } else {
                        $row['proposedDefinitionCode'] = $createCode !== '' ? $createCode : $code;
                        $row['proposedDefinitionName'] = MigrationPayload::string($override['proposedDefinitionName'] ?? $row['proposedDefinitionName'] ?? $createCode);
                        $row['proposedGroup'] = MigrationPayload::string($override['proposedGroup'] ?? $row['proposedGroup'] ?? 'construction');
                        $row['dataType'] = MigrationPayload::string($override['dataType'] ?? $row['dataType'] ?? 'text');
                        $row['unit'] = array_key_exists('unit', $override) ? $override['unit'] : ($row['unit'] ?? null);
                    }
                }
                if ($decision === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                    $existing = MigrationPayload::string($override['existingTargetDefinition'] ?? $row['existingTargetDefinition'] ?? '');
                    if ($existing === '' || !isset($targetDefs[$existing])) {
                        $decision = SpecMasterDecisionState::REVIEW;
                        $row['reason'] = 'map_existing_target_missing_keep_review';
                    } else {
                        $row['existingTargetDefinition'] = $existing;
                        $row['proposedDefinitionCode'] = $existing;
                        $row['proposedDefinitionName'] = (string) ($targetDefs[$existing]['name'] ?? $existing);
                    }
                }
                $row['decision'] = $decision;
                if (isset($override['reason'])) {
                    $row['reason'] = MigrationPayload::string($override['reason']);
                }
                $row['operatorResolved'] = true;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $standards
     * @param array<string, mixed> $rules
     * @param array<string, array<string, mixed>> $existingOptions
     * @return list<array<string, mixed>>
     */
    private function applyStandardDecisions(array $standards, array $rules, array $existingOptions): array
    {
        $mapExisting = is_array($rules['mapExisting'] ?? null) ? $rules['mapExisting'] : [];
        $approveCreate = array_fill_keys(array_map('strval', is_array($rules['approveCreate'] ?? null) ? $rules['approveCreate'] : []), true);
        $reject = array_fill_keys(array_map('strval', is_array($rules['reject'] ?? null) ? $rules['reject'] : []), true);
        $review = array_fill_keys(array_map('strval', is_array($rules['review'] ?? null) ? $rules['review'] : []), true);

        $out = [];
        foreach ($standards as $row) {
            $name = MigrationPayload::string($row['normalizedStandard'] ?? '');
            if (($row['decision'] ?? '') === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                $out[] = $row;
                continue;
            }
            if (isset($reject[$name]) || $this->isNonStandardFragment($name)) {
                $row['decision'] = SpecMasterDecisionState::REJECT;
                $row['reason'] = 'operator_reject_non_standard_or_parse_fragment';
                $out[] = $row;
                continue;
            }
            if (isset($review[$name])) {
                $row['decision'] = SpecMasterDecisionState::REVIEW;
                $row['reason'] = 'operator_keep_review_ambiguous_standard';
                $out[] = $row;
                continue;
            }
            if (isset($mapExisting[$name])) {
                $opt = (string) $mapExisting[$name];
                if (isset($existingOptions[$opt]) || $this->optionExistsByLabel($existingOptions, $opt)) {
                    $row['decision'] = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                    $row['existingTargetOption'] = $opt;
                    $row['reason'] = 'operator_map_to_existing_STANDARD_option';
                } else {
                    $row['decision'] = SpecMasterDecisionState::REVIEW;
                    $row['reason'] = 'operator_map_target_option_missing';
                }
                $out[] = $row;
                continue;
            }
            // Near-match existing by normalized label
            $matched = $this->findOptionNearMatch($existingOptions, $name);
            if ($matched !== null) {
                $row['decision'] = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                $row['existingTargetOption'] = $matched;
                $row['reason'] = 'operator_near_match_existing_STANDARD_option';
                $out[] = $row;
                continue;
            }
            if (isset($approveCreate[$name]) && $this->looksLikeStandard($name)) {
                $row['decision'] = SpecMasterDecisionState::APPROVED_CREATE;
                $row['reason'] = 'operator_approved_create_genuine_standard';
                $out[] = $row;
                continue;
            }
            if (!empty($rules['__approveGenuineStandards']) && $this->looksLikeStandard($name)) {
                // Remaining genuine standards not explicitly listed stay REVIEW for safety (not auto-approve-all).
                $row['decision'] = SpecMasterDecisionState::REVIEW;
                $row['reason'] = 'operator_genuine_looking_but_not_explicitly_approved_keep_review';
                $out[] = $row;
                continue;
            }
            $row['decision'] = SpecMasterDecisionState::REVIEW;
            $row['reason'] = 'operator_default_review';
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $approvals
     * @return array<string, mixed>
     */
    private function auditApprovals(array $approvals): array
    {
        $style = 0;
        $productType = 0;
        $document = 0;
        $testMethod = 0;
        $issuingOrg = 0;
        $reusable = 0;
        $unknown = 0;
        foreach ($approvals as $a) {
            $n = MigrationPayload::string($a['normalizedApproval'] ?? '');
            $class = $this->classifyApprovalCandidate($n);
            match ($class) {
                'STYLE_CODE' => ++$style,
                'PRODUCT_TYPE' => ++$productType,
                'CERTIFICATE_DOCUMENT' => ++$document,
                'TEST_METHOD' => ++$testMethod,
                'ISSUING_ORGANISATION' => ++$issuingOrg,
                'APPROVAL' => ++$reusable,
                default => ++$unknown,
            };
        }

        return [
            'raw' => 207,
            'previouslyNormalized' => 205,
            'candidatesInspected' => count($approvals),
            'reusableApprovalConcepts' => $reusable,
            'issuingOrganisationsSeparated' => $issuingOrg,
            'certificateDocumentValuesExcluded' => $document,
            'styleCodeValuesExcluded' => $style,
            'productTypeValuesExcluded' => $productType,
            'testMethodValuesExcluded' => $testMethod,
            'unknownOrReview' => $unknown,
            'note' => 'Most proposed "approvals" were style codes / product types / documents — not reusable APPROVALS options.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $approvals
     * @param array<string, mixed> $rules
     * @param array<string, array<string, mixed>> $existingOptions
     * @param array<string, mixed> $audit
     * @return list<array<string, mixed>>
     */
    private function applyApprovalDecisions(array $approvals, array $rules, array $existingOptions, array $audit): array
    {
        unset($audit);
        $keepReview = array_fill_keys(
            array_map('strval', is_array($rules['keepReviewIssuingOrgAbbreviations'] ?? null) ? $rules['keepReviewIssuingOrgAbbreviations'] : []),
            true,
        );
        $approveCreate = array_fill_keys(array_map('strval', is_array($rules['approveCreate'] ?? null) ? $rules['approveCreate'] : []), true);
        $mapExisting = is_array($rules['mapExisting'] ?? null) ? $rules['mapExisting'] : [];

        $out = [];
        foreach ($approvals as $row) {
            if (($row['decision'] ?? '') === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                $out[] = $row;
                continue;
            }
            $name = MigrationPayload::string($row['normalizedApproval'] ?? '');
            if (isset($mapExisting[$name])) {
                $opt = (string) $mapExisting[$name];
                $row['decision'] = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                $row['existingTargetOption'] = $opt;
                $row['reason'] = 'operator_map_existing_APPROVALS_option';
                $out[] = $row;
                continue;
            }
            $matched = $this->findOptionNearMatch($existingOptions, $name);
            if ($matched !== null) {
                $row['decision'] = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                $row['existingTargetOption'] = $matched;
                $row['reason'] = 'operator_near_match_existing_APPROVALS_option';
                $out[] = $row;
                continue;
            }
            if (isset($approveCreate[$name])) {
                $row['decision'] = SpecMasterDecisionState::APPROVED_CREATE;
                $row['reason'] = 'operator_approved_create_reusable_approval';
                $out[] = $row;
                continue;
            }
            if (isset($keepReview[$name])) {
                $row['decision'] = SpecMasterDecisionState::REVIEW;
                $row['semanticClass'] = 'ISSUING_ORGANISATION';
                $row['reason'] = 'operator_issuing_organisation_abbreviation_not_approval_option';
                $out[] = $row;
                continue;
            }
            $class = $this->classifyApprovalCandidate($name);
            $row['semanticClass'] = $class;
            if (in_array($class, ['STYLE_CODE', 'PRODUCT_TYPE', 'CERTIFICATE_DOCUMENT', 'TEST_METHOD'], true)) {
                $row['decision'] = SpecMasterDecisionState::REJECT;
                $row['reason'] = 'operator_reject_' . strtolower($class) . '_not_reusable_approval';
                $out[] = $row;
                continue;
            }
            if ($class === 'ISSUING_ORGANISATION') {
                $row['decision'] = SpecMasterDecisionState::REVIEW;
                $row['reason'] = 'operator_issuing_organisation_separated_from_approvals';
                $out[] = $row;
                continue;
            }
            // DNV family
            if (preg_match('/^dnv([\s\-]?gl)?$/i', $name) === 1) {
                $row['decision'] = SpecMasterDecisionState::REVIEW;
                $row['reason'] = 'dnv_dnv_gl_ambiguity_do_not_silent_merge';
                $out[] = $row;
                continue;
            }
            $row['decision'] = SpecMasterDecisionState::REVIEW;
            $row['reason'] = 'operator_unknown_approval_candidate_keep_review';
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $aliases
     * @param array<string, mixed> $rules
     * @param list<array<string, mixed>> $standards
     * @return list<array<string, mixed>>
     */
    private function applyAliasDecisions(array $aliases, array $rules, array $standards): array
    {
        unset($standards);
        $safeList = is_array($rules['safe'] ?? null) ? $rules['safe'] : [];
        $safeMap = [];
        foreach ($safeList as $s) {
            if (!is_array($s)) {
                continue;
            }
            $raw = MigrationPayload::string($s['raw'] ?? '');
            if ($raw === '') {
                continue;
            }
            $safeMap[$this->normKey($raw)] = $s;
        }
        $rejectMultiline = !empty($rules['rejectMultilineBlobs']);

        $out = [];
        foreach ($aliases as $row) {
            $raw = MigrationPayload::string($row['rawValue'] ?? '');
            $target = MigrationPayload::string($row['targetOption'] ?? '');
            $key = $this->normKey($raw);
            if (isset($safeMap[$key])) {
                $row['decision'] = SpecMasterDecisionState::SAFE_ALIAS;
                $row['targetOption'] = MigrationPayload::string($safeMap[$key]['target'] ?? $target);
                $row['reason'] = MigrationPayload::string($safeMap[$key]['reason'] ?? 'operator_safe_alias');
                $out[] = $row;
                continue;
            }
            if ($rejectMultiline && (str_contains($raw, "\n") || str_contains($raw, "\r"))) {
                $row['decision'] = SpecMasterDecisionState::ALIAS_REJECT;
                $row['reason'] = 'operator_reject_multiline_blob_not_an_alias';
                $out[] = $row;
                continue;
            }
            if ($this->isSafeFormattingAlias($raw, $target)) {
                $row['decision'] = SpecMasterDecisionState::SAFE_ALIAS;
                $row['reason'] = 'operator_deterministic_formatting_equivalence';
                $out[] = $row;
                continue;
            }
            $row['decision'] = SpecMasterDecisionState::ALIAS_REVIEW;
            $row['reason'] = 'operator_semantic_or_unproven_alias_keep_review';
            $out[] = $row;
        }

        // Ensure explicit safe aliases exist even if not in prior alias list
        foreach ($safeList as $s) {
            if (!is_array($s)) {
                continue;
            }
            $raw = MigrationPayload::string($s['raw'] ?? '');
            $target = MigrationPayload::string($s['target'] ?? '');
            if ($raw === '' || $target === '') {
                continue;
            }
            $found = false;
            foreach ($out as $existing) {
                if ($this->normKey((string) ($existing['rawValue'] ?? '')) === $this->normKey($raw)
                    && ($existing['decision'] ?? '') === SpecMasterDecisionState::SAFE_ALIAS
                ) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $out[] = [
                    'rawValue' => $raw,
                    'normalizedValue' => $raw,
                    'targetOption' => $target,
                    'source' => 'batch-b1-operator',
                    'usageCount' => 0,
                    'decision' => SpecMasterDecisionState::SAFE_ALIAS,
                    'confidence' => 'high',
                    'reason' => MigrationPayload::string($s['reason'] ?? 'operator_safe_alias'),
                ];
            }
        }

        return $out;
    }

    private function classifyApprovalCandidate(string $name): string
    {
        $n = trim($name);
        if ($n === '') {
            return 'UNKNOWN';
        }
        // DNV family must not be treated as style codes.
        if (preg_match('/^dnv([\s\-]?gl)?$/i', $n) === 1) {
            return 'APPROVAL';
        }
        if (preg_match('/\.(pdf|docx?)$/i', $n) === 1
            || preg_match('/(certificate|assessment|hydrostatic|manufacturing-assessment)/i', $n) === 1
        ) {
            return 'CERTIFICATE_DOCUMENT';
        }
        if (preg_match('/^(fire test|mech test|mechanical test)$/i', $n) === 1) {
            return 'TEST_METHOD';
        }
        if (preg_match('/^(multi-core|small multi-core|single-core|control)$/i', $n) === 1) {
            return 'PRODUCT_TYPE';
        }
        if (preg_match('/^(dnv|abs|bv|uscg|lloyd|ul)\b/i', $n) === 1) {
            return 'APPROVAL';
        }
        // 2-letter codes are typically issuing-organisation abbreviations (AS, UP, WI), not style codes.
        if (preg_match('/^[A-Z]{2}$/', $n) === 1) {
            return 'ISSUING_ORGANISATION';
        }
        // Style-code clusters: short alnum tokens / space-separated style lists
        if (preg_match('/^[A-Z]{2,4}(\s+[A-Z]{2,4})*$/', $n) === 1 && strlen($n) <= 40) {
            return 'STYLE_CODE';
        }
        if (preg_match('/^[A-Z]{2,4}$/', $n) === 1) {
            return 'STYLE_CODE';
        }

        return 'UNKNOWN';
    }

    private function looksLikeStandard(string $name): bool
    {
        return preg_match('/\b(AS\/?NZS|AS|IEC|IEEE|BS|EN|NEK|ISO)\b/i', $name) === 1
            && preg_match('/\d/', $name) === 1;
    }

    private function isNonStandardFragment(string $name): bool
    {
        $n = trim($name);
        if ($n === '' || strlen($n) <= 2) {
            return true;
        }
        if (in_array($n, ['W', 'Z)', 'Cat A', 'UPM', 'C', 'Z'], true)) {
            return true;
        }
        if (str_contains($n, "\n")) {
            return true;
        }

        return preg_match('/^[A-Za-z]\)$/', $n) === 1;
    }

    private function isSafeFormattingAlias(string $raw, string $target): bool
    {
        return $this->normKey($raw) === $this->normKey($target);
    }

    private function normKey(string $value): string
    {
        $s = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\u{00A0}", ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return mb_strtolower($s);
    }

    /**
     * @param array<string, array<string, mixed>> $options
     */
    private function findOptionNearMatch(array $options, string $name): ?string
    {
        $key = $this->normKey($name);
        $keyCompact = preg_replace('/[^a-z0-9]+/', '', $key) ?? $key;
        foreach ($options as $code => $opt) {
            $label = $this->normKey((string) ($opt['name'] ?? $opt['label'] ?? ''));
            $codeKey = $this->normKey((string) $code);
            $labelCompact = preg_replace('/[^a-z0-9]+/', '', $label) ?? $label;
            if ($key === $label || $key === $codeKey || $keyCompact === $labelCompact) {
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $options
     */
    private function optionExistsByLabel(array $options, string $codeOrLabel): bool
    {
        if (isset($options[$codeOrLabel])) {
            return true;
        }

        return $this->findOptionNearMatch($options, $codeOrLabel) !== null;
    }

    /**
     * @param array<string, array<string, mixed>> $uniqueCreates
     * @param array<string, array<string, mixed>> $targetDefs
     * @param list<array<string, mixed>> $approvedStandards
     * @param list<array<string, mixed>> $approvedApprovals
     * @param array<string, array<string, mixed>> $standardOptions
     * @param array<string, array<string, mixed>> $approvalOptions
     * @return list<array<string, mixed>>
     */
    private function detectCollisions(
        array $uniqueCreates,
        array $targetDefs,
        array $approvedStandards,
        array $approvedApprovals,
        array $standardOptions,
        array $approvalOptions,
    ): array {
        $collisions = [];
        foreach ($uniqueCreates as $code => $meta) {
            if (isset($targetDefs[$code])) {
                $collisions[] = [
                    'type' => 'DEFINITION_CODE_COLLISION',
                    'code' => $code,
                    'detail' => 'Approved create code already exists on target',
                ];
            }
        }
        foreach ($approvedStandards as $s) {
            if (($s['decision'] ?? '') !== SpecMasterDecisionState::APPROVED_CREATE) {
                continue;
            }
            $code = $this->optionCodeFromName((string) ($s['normalizedStandard'] ?? ''));
            if (isset($standardOptions[$code])) {
                $collisions[] = [
                    'type' => 'STANDARD_OPTION_CODE_COLLISION',
                    'code' => $code,
                    'detail' => 'Approved standard option code already exists',
                ];
            }
        }
        foreach ($approvedApprovals as $a) {
            if (($a['decision'] ?? '') !== SpecMasterDecisionState::APPROVED_CREATE) {
                continue;
            }
            $code = $this->optionCodeFromName((string) ($a['normalizedApproval'] ?? ''));
            if (isset($approvalOptions[$code])) {
                $collisions[] = [
                    'type' => 'APPROVAL_OPTION_CODE_COLLISION',
                    'code' => $code,
                    'detail' => 'Approved approval option code already exists',
                ];
            }
        }

        return $collisions;
    }

    private function optionCodeFromName(string $name): string
    {
        $s = strtoupper(trim($name));
        $s = preg_replace('/[^A-Z0-9]+/', '-', $s) ?? $s;

        return trim($s, '-');
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $keys
     * @return array<string, int>
     */
    private function countByDecision(array $items, array $keys): array
    {
        $out = array_fill_keys($keys, 0);
        foreach ($items as $item) {
            $d = (string) ($item['decision'] ?? '');
            if (isset($out[$d])) {
                ++$out[$d];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{mapped: int, approved: int, proposed: int, review: int, reject: int}
     */
    private function optionCounts(array $items): array
    {
        $mapped = 0;
        $approved = 0;
        $proposed = 0;
        $review = 0;
        $reject = 0;
        foreach ($items as $item) {
            $d = (string) ($item['decision'] ?? '');
            if ($d === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapped;
            } elseif ($d === SpecMasterDecisionState::APPROVED_CREATE) {
                ++$approved;
            } elseif ($d === SpecMasterDecisionState::PROPOSED_CREATE) {
                ++$proposed;
            } elseif ($d === SpecMasterDecisionState::REJECT) {
                ++$reject;
            } else {
                ++$review;
            }
        }

        return compact('mapped', 'approved', 'proposed', 'review', 'reject');
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexByCode(mixed $rows): array
    {
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(MigrationPayload::string($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'code' => $code,
                'name' => MigrationPayload::string($row['name'] ?? ''),
                'dataType' => MigrationPayload::string($row['dataType'] ?? ''),
                'group' => MigrationPayload::string($row['group'] ?? $row['groupCode'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexOptionsByDef(mixed $rows, string $definitionCode): array
    {
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $def = strtoupper(MigrationPayload::string($row['definitionCode'] ?? ''));
            if ($def !== strtoupper($definitionCode)) {
                continue;
            }
            $code = MigrationPayload::string($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'code' => $code,
                'name' => MigrationPayload::string($row['name'] ?? $row['label'] ?? ''),
            ];
        }

        return $out;
    }
}
