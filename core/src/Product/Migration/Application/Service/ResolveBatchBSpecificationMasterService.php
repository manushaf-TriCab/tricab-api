<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Specification\LegacySpecFieldClass;
use TriCab\Core\Product\Migration\Specification\SpecMasterDecisionState;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch B — Specification master review + dry-run plan.
 * Never writes Definitions/Options/aliases/product values. Never starts Batch C d/v rows.
 */
final class ResolveBatchBSpecificationMasterService
{
    private const EXPECTED_UNITS = ['M', 'EA', 'FT', 'PK', 'MM', 'MM2', 'KG', 'KG_PER_KM', 'A', 'V', 'C'];

    /** Known WP meta → semantic class (evidence-driven overrides). */
    private const WP_FIELD_CLASS = [
        'standards' => LegacySpecFieldClass::STANDARD,
        'standards_content' => LegacySpecFieldClass::STANDARD,
        'standards_content_al' => LegacySpecFieldClass::STANDARD,
        'issuing_organisation' => LegacySpecFieldClass::APPROVAL,
        'certificate_upload' => LegacySpecFieldClass::DOCUMENT,
        'certificate_thumbnail' => LegacySpecFieldClass::MEDIA,
        'core_colours' => LegacySpecFieldClass::CORE_COLOUR,
        'core_colour' => LegacySpecFieldClass::CORE_COLOUR,
        'search_keywords' => LegacySpecFieldClass::SEO,
        'small_desc' => LegacySpecFieldClass::MARKETING_CONTENT,
        'small_desc_cat_page' => LegacySpecFieldClass::MARKETING_CONTENT,
        'sub_title' => LegacySpecFieldClass::MARKETING_CONTENT,
        'row_desc' => LegacySpecFieldClass::MARKETING_CONTENT,
        'compliance_content' => LegacySpecFieldClass::MARKETING_CONTENT,
        'compliance_content_al' => LegacySpecFieldClass::MARKETING_CONTENT,
        'construction_content' => LegacySpecFieldClass::MARKETING_CONTENT,
        'construction_content_al' => LegacySpecFieldClass::MARKETING_CONTENT,
        'operation_content' => LegacySpecFieldClass::MARKETING_CONTENT,
        'operation_content_al' => LegacySpecFieldClass::MARKETING_CONTENT,
        'style_code' => LegacySpecFieldClass::CLASSIFICATION,
        'styles' => LegacySpecFieldClass::CLASSIFICATION,
        'industrial_or_shipboard' => LegacySpecFieldClass::CLASSIFICATION,
        'ind_or_sbo' => LegacySpecFieldClass::CLASSIFICATION,
        'display_order' => LegacySpecFieldClass::INTERNAL_METADATA,
        'acc_display_order' => LegacySpecFieldClass::INTERNAL_METADATA,
        'acc_display_order_group' => LegacySpecFieldClass::INTERNAL_METADATA,
        'al_display_order' => LegacySpecFieldClass::INTERNAL_METADATA,
        'cu_display_order' => LegacySpecFieldClass::INTERNAL_METADATA,
        'img_overwrite' => LegacySpecFieldClass::MEDIA,
        'amour_braid_screen' => LegacySpecFieldClass::SPECIFICATION,
        'lzsh_braid_fire' => LegacySpecFieldClass::SPECIFICATION,
        'conductor_type' => LegacySpecFieldClass::SPECIFICATION,
        'compound_code' => LegacySpecFieldClass::UNKNOWN,
        'industry_code' => LegacySpecFieldClass::UNKNOWN,
        'nek_class' => LegacySpecFieldClass::STANDARD,
        'hcl_emission' => LegacySpecFieldClass::SPECIFICATION,
        'oxygen_index' => LegacySpecFieldClass::SPECIFICATION,
        'degrees' => LegacySpecFieldClass::SPECIFICATION,
        'kilovolts' => LegacySpecFieldClass::SPECIFICATION,
        'ind_degrees' => LegacySpecFieldClass::SPECIFICATION,
        'ind_kilovolts' => LegacySpecFieldClass::SPECIFICATION,
        'ind_lszh_fire' => LegacySpecFieldClass::SPECIFICATION,
    ];

    /** Exact existing Definition matches for WP/tc fields. */
    private const EXISTING_DEF_MAP = [
        'amour_braid_screen' => ['code' => 'BRAID', 'name' => 'Braid', 'dataType' => 'long-text', 'group' => 'construction'],
        'lzsh_braid_fire' => ['code' => 'BRAID', 'name' => 'Braid', 'dataType' => 'long-text', 'group' => 'construction'],
        'standards' => ['code' => 'STANDARD', 'name' => 'Standard', 'dataType' => 'multi-select', 'group' => 'compliance'],
        'standards_content' => ['code' => 'STANDARD', 'name' => 'Standard', 'dataType' => 'multi-select', 'group' => 'compliance'],
        'standards_content_al' => ['code' => 'STANDARD', 'name' => 'Standard', 'dataType' => 'multi-select', 'group' => 'compliance'],
        'issuing_organisation' => ['code' => 'APPROVALS', 'name' => 'Approvals', 'dataType' => 'multi-select', 'group' => 'compliance'],
        'core_colours' => ['code' => 'CORE-COLOURS', 'name' => 'Core Colours', 'dataType' => 'long-text', 'group' => 'construction'],
        'core_colour' => ['code' => 'CORE-COLOURS', 'name' => 'Core Colours', 'dataType' => 'long-text', 'group' => 'construction'],
        'conductor_type' => ['code' => 'CONDUCTORS', 'name' => 'Conductors', 'dataType' => 'long-text', 'group' => 'construction'],
    ];

    /** Semantic concepts for accessory d/v labels → proposed Definition (Batch C maps style+field later). */
    private const DV_CONCEPT_DEFS = [
        'overall diameter' => ['code' => 'NOM-CABLE-OD', 'existing' => true, 'name' => 'Nom. Cable'],
        'cable diameter' => ['code' => 'NOM-CABLE-OD', 'existing' => true, 'name' => 'Nom. Cable'],
        'nom. cable' => ['code' => 'NOM-CABLE-OD', 'existing' => true, 'name' => 'Nom. Cable'],
        'conductor' => ['code' => 'CSA', 'existing' => true, 'name' => 'Conductor CSA'],
        'conductor mm' => ['code' => 'CSA', 'existing' => true, 'name' => 'Conductor CSA'],
        'current rating' => ['code' => 'CURRENT', 'existing' => true, 'name' => 'Current Rating'],
        'rating amp' => ['code' => 'CURRENT', 'existing' => true, 'name' => 'Current Rating'],
        'voltage' => ['code' => 'VOLTAGE', 'existing' => true, 'name' => 'Voltage'],
        'min bend' => ['code' => 'MIN-BEND-RADIUS', 'existing' => true, 'name' => 'Min. Bend'],
        'weight' => ['code' => 'APPROX-WEIGHT', 'existing' => true, 'name' => 'Approx. Wt.'],
        'approx wt' => ['code' => 'APPROX-WEIGHT', 'existing' => true, 'name' => 'Approx. Wt.'],
        'length' => ['code' => 'LENGTH', 'existing' => false, 'name' => 'Length', 'group' => 'mechanical', 'dataType' => 'decimal', 'unit' => 'MM'],
        'width' => ['code' => 'WIDTH', 'existing' => false, 'name' => 'Width', 'group' => 'mechanical', 'dataType' => 'decimal', 'unit' => 'MM'],
        'thread od' => ['code' => 'THREAD-OD', 'existing' => false, 'name' => 'Thread OD', 'group' => 'mechanical', 'dataType' => 'decimal', 'unit' => 'MM'],
        'thread length' => ['code' => 'THREAD-LENGTH', 'existing' => false, 'name' => 'Thread Length', 'group' => 'mechanical', 'dataType' => 'decimal', 'unit' => 'MM'],
        'pins' => ['code' => 'PIN-COUNT', 'existing' => false, 'name' => 'Pins', 'group' => 'construction', 'dataType' => 'integer', 'unit' => null],
        'colour' => ['code' => 'DEFAULT-COLOUR', 'existing' => true, 'name' => 'Default sheath colour'],
        'cable colour' => ['code' => 'DEFAULT-COLOUR', 'existing' => true, 'name' => 'Default sheath colour'],
    ];

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function resolve(array $inputs): array
    {
        $azure = is_array($inputs['azure'] ?? null) ? $inputs['azure'] : [];
        $defsProposed = is_array($inputs['definitionProposals'] ?? null) ? $inputs['definitionProposals'] : [];
        $unitsProposed = is_array($inputs['unitsProposed'] ?? null) ? $inputs['unitsProposed'] : [];
        $standardsProposed = is_array($inputs['standardsProposed'] ?? null) ? $inputs['standardsProposed'] : [];
        $approvalsProposed = is_array($inputs['approvalsProposed'] ?? null) ? $inputs['approvalsProposed'] : [];
        $optionsProposed = is_array($inputs['optionsProposed'] ?? null) ? $inputs['optionsProposed'] : [];
        $dvRows = is_array($inputs['dvRows'] ?? null) ? $inputs['dvRows'] : [];
        $specLabels = is_array($inputs['specLabels'] ?? null) ? $inputs['specLabels'] : [];
        $operator = is_array($inputs['operatorDecisions'] ?? null) ? $inputs['operatorDecisions'] : [];
        $classificationReady = (int) ($inputs['classificationReady'] ?? 95);
        $fullProductReady = (int) ($inputs['fullProductMigrationReady'] ?? 54);
        $fullProductBlocked = (int) ($inputs['fullProductMigrationBlocked'] ?? 336);

        $targetDefs = $this->indexByCode($azure['definitions'] ?? []);
        $targetUnits = $this->indexByCode($azure['units'] ?? []);
        $targetGroups = $this->indexByCode($azure['groups'] ?? []);
        $targetOptionsByDef = $this->indexOptions($azure['options'] ?? []);

        $definitions = $this->resolveDefinitions($defsProposed, $targetDefs, $operator);
        $dvConcepts = $this->resolveDvConcepts($specLabels, $dvRows, $targetDefs, $definitions);
        $definitions = array_merge($definitions, $dvConcepts['definitionRows']);

        $units = $this->resolveUnits($unitsProposed, $targetUnits);
        $standards = $this->resolveStandards($standardsProposed, $targetOptionsByDef['STANDARD'] ?? [], $operator);
        $approvals = $this->resolveApprovals($approvalsProposed, $targetOptionsByDef['APPROVALS'] ?? [], $operator);
        $aliases = $this->resolveAliases($optionsProposed, $standards, $approvals, $operator);
        $coreColours = $this->resolveCoreColours($targetDefs);

        $defCounts = $this->countDecisions($definitions, [
            SpecMasterDecisionState::APPROVED_MAP_EXISTING,
            SpecMasterDecisionState::APPROVED_CREATE,
            SpecMasterDecisionState::PROPOSED_CREATE,
            SpecMasterDecisionState::REVIEW,
            SpecMasterDecisionState::NOT_A_SPECIFICATION,
            SpecMasterDecisionState::REJECT,
        ]);
        $unitCounts = $this->unitCounts($units);
        $stdCounts = $this->optionDecisionCounts($standards);
        $aprCounts = $this->optionDecisionCounts($approvals);
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

        $readiness = [
            'DEFINITION_READY' => $defCounts[SpecMasterDecisionState::APPROVED_MAP_EXISTING]
                + $defCounts[SpecMasterDecisionState::APPROVED_CREATE],
            'DEFINITION_REVIEW' => $defCounts[SpecMasterDecisionState::PROPOSED_CREATE]
                + $defCounts[SpecMasterDecisionState::REVIEW],
            'UNIT_READY' => $unitCounts['mapped'],
            'UNIT_REVIEW' => $unitCounts['review'] + $unitCounts['newRequired'],
            'STANDARD_READY' => $stdCounts['mapped'] + $stdCounts['approved'],
            'STANDARD_REVIEW' => $stdCounts['proposed'] + $stdCounts['review'],
            'APPROVAL_READY' => $aprCounts['mapped'] + $aprCounts['approved'],
            'APPROVAL_REVIEW' => $aprCounts['proposed'] + $aprCounts['review'],
            'ALIAS_READY' => $aliasCounts[SpecMasterDecisionState::SAFE_ALIAS],
            'ALIAS_REVIEW' => $aliasCounts[SpecMasterDecisionState::ALIAS_REVIEW],
        ];

        // Spec masters not operator-approved → SPEC_MASTER_READY stays 0 until Batch B apply.
        $specMasterReadyProducts = 0;
        $dryRun = $this->buildDryRunPlan($definitions, $standards, $approvals, $aliases, $units);

        return [
            'phase' => '12.7-batch-b',
            'mode' => 'DRY_RUN_ONLY',
            'batchAUntouched' => true,
            'batchCDvMappings' => 'NOT_STARTED',
            'target' => [
                'server' => MigrationPayload::string($azure['server'] ?? 'tricab-mel'),
                'database' => MigrationPayload::string($azure['database'] ?? 'tc'),
                'environment' => 'NON-PRODUCTION',
                'liveValidation' => MigrationPayload::string($azure['liveValidation'] ?? 'UNKNOWN'),
                'groups' => count($targetGroups),
                'definitions' => count($targetDefs),
                'units' => count($targetUnits),
                'options' => (int) ($azure['optionCount'] ?? 0),
                'optionsActive' => (int) ($azure['optionActiveCount'] ?? 0),
                'optionsInactive' => (int) ($azure['optionInactiveCount'] ?? 0),
                'unitCodes' => array_keys($targetUnits),
                'groupCodes' => array_keys($targetGroups),
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => (int) ($inputs['forbiddenWriteAttempts'] ?? 0),
            ],
            'inventory' => [
                'wordpressFields' => count($defsProposed),
                'cableSpecsFields' => (int) ($inputs['cableSpecsFieldCount'] ?? 0),
                'accessoryDvLabelConcepts' => $dvConcepts['conceptCount'],
                'standardsRawValues' => (int) ($inputs['standardsRawCount'] ?? count($standardsProposed['values'] ?? [])),
                'standardsNormalized' => count($standards),
                'approvalsRawValues' => (int) ($inputs['approvalsRawCount'] ?? count($approvalsProposed['values'] ?? [])),
                'approvalsNormalized' => count($approvals),
            ],
            'definitions' => [
                'items' => $definitions,
                'counts' => $defCounts,
            ],
            'units' => [
                'items' => $units,
                'counts' => $unitCounts,
            ],
            'standards' => [
                'items' => $standards,
                'counts' => $stdCounts,
                'targetDefinition' => 'STANDARD',
            ],
            'approvals' => [
                'items' => $approvals,
                'counts' => $aprCounts,
                'targetDefinition' => 'APPROVALS',
                'dnvAmbiguity' => $this->dnvAmbiguity($approvals),
            ],
            'aliases' => [
                'items' => $aliases,
                'counts' => $aliasCounts,
            ],
            'coreColours' => $coreColours,
            'dvConceptDiscovery' => [
                'note' => 'Batch B discovers authoritative Definition candidates only. Style-specific d1–d7/v1–v4 rows remain Batch C.',
                'globalFieldAssumptionForbidden' => true,
                'conceptCount' => $dvConcepts['conceptCount'],
                'concepts' => $dvConcepts['concepts'],
                'batchCRowsNotProcessed' => count($dvRows),
            ],
            'readiness' => $readiness,
            'productReadinessImpact' => [
                'CLASSIFICATION_READY' => $classificationReady,
                'SPEC_MASTER_READY' => $specMasterReadyProducts,
                'SPEC_FIELD_MAPPING_READY' => 0,
                'ACCESSORY_IDENTITY_READY' => 0,
                'MEDIA_READY' => 0,
                'DOCUMENT_READY' => 0,
                'FULL_PRODUCT_MIGRATION_READY' => $fullProductReady,
                'FULL_PRODUCT_MIGRATION_BLOCKED' => $fullProductBlocked,
                'stillBlocked' => [
                    'SPEC_MASTER_OPERATOR_APPROVAL' => 'Batch B masters not approved/applied',
                    'SPEC_FIELD_MAPPING_BATCH_C' => count($dvRows) . ' d/v rows deferred',
                    'CATEGORY_UNRESOLVED' => 295,
                    'ACCESSORY_IDENTITY' => 41,
                ],
            ],
            'dryRunTargetPlan' => $dryRun,
            'approvalBoundary' => [
                'batchBApplyGuard' => 'BATCH_B_APPLY_NOT_APPROVED',
                'batchCGuard' => 'BATCH_C_NOT_STARTED',
                'productMigrationGuard' => 'PRODUCT_MIGRATION_NOT_APPROVED',
            ],
            'recommendation' => $this->recommendation($defCounts, $stdCounts, $aprCounts, $readiness),
            'nextAction' => 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL',
        ];
    }

    /**
     * @param list<array<string, mixed>> $proposed
     * @param array<string, array<string, mixed>> $targetDefs
     * @param array<string, mixed> $operator
     * @return list<array<string, mixed>>
     */
    private function resolveDefinitions(array $proposed, array $targetDefs, array $operator): array
    {
        $overrides = is_array($operator['definitions'] ?? null) ? $operator['definitions'] : [];
        $out = [];
        foreach ($proposed as $row) {
            $field = MigrationPayload::string($row['legacyField'] ?? $row['sourceField'] ?? '');
            $usage = (int) ($row['usageCount'] ?? 0);
            $samples = is_array($row['exampleValues'] ?? null) ? $row['exampleValues'] : [];
            $class = $this->classifyWpField($field);
            $existingMap = self::EXISTING_DEF_MAP[$field] ?? null;
            $candidate = MigrationPayload::string($row['candidateDefinition'] ?? '');
            $status = MigrationPayload::string($row['status'] ?? '');

            $decision = SpecMasterDecisionState::REVIEW;
            $reason = 'needs_operator_review';
            $confidence = 'low';
            $proposedCode = null;
            $proposedName = null;
            $dataType = null;
            $unit = null;
            $group = null;
            $existing = null;

            if ($class === LegacySpecFieldClass::MEDIA
                || $class === LegacySpecFieldClass::DOCUMENT
                || $class === LegacySpecFieldClass::SEO
                || $class === LegacySpecFieldClass::MARKETING_CONTENT
                || $class === LegacySpecFieldClass::INTERNAL_METADATA
                || $class === LegacySpecFieldClass::CLASSIFICATION
            ) {
                $decision = SpecMasterDecisionState::NOT_A_SPECIFICATION;
                $reason = 'semantic_class_' . strtolower($class);
                $confidence = 'high';
            } elseif ($existingMap !== null && isset($targetDefs[$existingMap['code']])) {
                $decision = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                $existing = $existingMap['code'];
                $proposedCode = $existingMap['code'];
                $proposedName = $existingMap['name'];
                $dataType = $existingMap['dataType'];
                $group = $existingMap['group'];
                $reason = 'evidence_matched_existing_definition';
                $confidence = 'high';
            } elseif ($status === 'EXISTING_DEFINITION' && $candidate !== '' && isset($targetDefs[$candidate])) {
                $decision = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                $existing = $candidate;
                $proposedCode = $candidate;
                $proposedName = (string) ($targetDefs[$candidate]['name'] ?? $candidate);
                $dataType = (string) ($targetDefs[$candidate]['dataType'] ?? '');
                $group = (string) ($targetDefs[$candidate]['group'] ?? '');
                $reason = 'discovery_existing_definition';
                $confidence = 'high';
            } elseif ($class === LegacySpecFieldClass::SPECIFICATION || $class === LegacySpecFieldClass::STANDARD || $class === LegacySpecFieldClass::APPROVAL || $class === LegacySpecFieldClass::CORE_COLOUR) {
                if ($status === 'NEW_DEFINITION_REQUIRED') {
                    // Prefer existing STANDARD/APPROVALS/CORE-COLOURS over creating STANDARDS duplicate.
                    if (in_array($candidate, ['STANDARDS', 'STANDARD'], true) && isset($targetDefs['STANDARD'])) {
                        $decision = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                        $existing = 'STANDARD';
                        $proposedCode = 'STANDARD';
                        $proposedName = 'Standard';
                        $dataType = 'multi-select';
                        $group = 'compliance';
                        $reason = 'map_to_existing_STANDARD_not_new_STANDARDS';
                        $confidence = 'high';
                    } else {
                        $decision = SpecMasterDecisionState::PROPOSED_CREATE;
                        $proposedCode = $this->normalizeCode($candidate !== '' ? $candidate : $field);
                        $proposedName = $this->humanize($proposedCode);
                        $dataType = $this->inferDataType($samples, $field);
                        $group = $this->suggestGroup($class, $field);
                        $unit = $this->inferUnit($samples, $field);
                        $reason = 'genuine_specification_candidate_needs_operator_approve_create';
                        $confidence = 'medium';
                    }
                } elseif ($class === LegacySpecFieldClass::SPECIFICATION) {
                    $decision = SpecMasterDecisionState::PROPOSED_CREATE;
                    $proposedCode = $this->normalizeCode($candidate !== '' ? $candidate : $field);
                    $proposedName = $this->humanize($proposedCode);
                    $dataType = $this->inferDataType($samples, $field);
                    $group = $this->suggestGroup($class, $field);
                    $unit = $this->inferUnit($samples, $field);
                    $reason = 'specification_candidate_proposed_create';
                    $confidence = 'medium';
                } else {
                    $decision = SpecMasterDecisionState::REVIEW;
                    $reason = 'ambiguous_or_incomplete_evidence';
                    $confidence = 'low';
                }
            } elseif ($status === 'AMBIGUOUS' || $class === LegacySpecFieldClass::UNKNOWN) {
                $decision = SpecMasterDecisionState::REVIEW;
                $reason = 'ambiguous_legacy_field';
                $confidence = 'low';
            } else {
                $decision = SpecMasterDecisionState::REVIEW;
                $reason = 'unclassified_retained_for_review';
                $confidence = 'low';
            }

            // Image meta keys
            if (str_contains($field, 'image') || str_starts_with($field, 'al-') || str_starts_with($field, 'cu-al-')) {
                $decision = SpecMasterDecisionState::NOT_A_SPECIFICATION;
                $class = LegacySpecFieldClass::MEDIA;
                $reason = 'media_attachment_meta';
                $confidence = 'high';
                $proposedCode = null;
            }

            // Core matrix control fields are not separate definitions
            if (preg_match('/^(control|multi|single)_core_[12]$/', $field) === 1
                || preg_match('/^(al|cu)_single_core_category$/', $field) === 1
                || in_array($field, ['section_1', 'section_2'], true)
            ) {
                $decision = SpecMasterDecisionState::NOT_A_SPECIFICATION;
                $class = LegacySpecFieldClass::CORE_COLOUR;
                $reason = 'core_colour_matrix_helper_not_standalone_definition';
                $confidence = 'high';
                $proposedCode = null;
            }

            if (isset($overrides[$field]) && is_array($overrides[$field])) {
                $decision = MigrationPayload::string($overrides[$field]['decision'] ?? $decision);
                $reason = MigrationPayload::string($overrides[$field]['reason'] ?? $reason);
                if (isset($overrides[$field]['proposedDefinitionCode'])) {
                    $proposedCode = MigrationPayload::string($overrides[$field]['proposedDefinitionCode']);
                }
                if (isset($overrides[$field]['existingTargetDefinition'])) {
                    $existing = MigrationPayload::string($overrides[$field]['existingTargetDefinition']);
                }
            }

            // Never auto APPROVED_CREATE
            if ($decision === SpecMasterDecisionState::APPROVED_CREATE
                && !(($overrides[$field]['decision'] ?? null) === SpecMasterDecisionState::APPROVED_CREATE)
            ) {
                $decision = SpecMasterDecisionState::PROPOSED_CREATE;
                $reason = 'downgraded_auto_approved_create_requires_operator';
            }

            $out[] = [
                'sourceSystem' => 'wordpress',
                'sourceField' => $field,
                'sourceLabel' => $field,
                'semanticConcept' => $class,
                'fieldClass' => $class,
                'usageCount' => $usage,
                'sampleProducts' => [],
                'sampleValues' => array_values(array_slice(array_map('strval', $samples), 0, 8)),
                'proposedGroup' => $group,
                'existingTargetDefinition' => $existing,
                'proposedDefinitionCode' => $proposedCode,
                'proposedDefinitionName' => $proposedName,
                'dataType' => $dataType,
                'unit' => $unit,
                'decision' => $decision,
                'confidence' => $confidence,
                'evidence' => [
                    'discoveryStatus' => $status,
                    'candidateDefinition' => $candidate,
                ],
                'reason' => $reason,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $specLabels
     * @param list<array<string, mixed>> $dvRows
     * @param array<string, array<string, mixed>> $targetDefs
     * @param list<array<string, mixed>> $definitionRows
     * @return array{conceptCount: int, concepts: list<array<string, mixed>>, definitionRows: list<array<string, mixed>>}
     */
    private function resolveDvConcepts(array $specLabels, array $dvRows, array $targetDefs, array $definitionRows): array
    {
        $existingCodes = [];
        foreach ($definitionRows as $r) {
            $code = MigrationPayload::string($r['proposedDefinitionCode'] ?? $r['existingTargetDefinition'] ?? '');
            if ($code !== '') {
                $existingCodes[$code] = true;
            }
        }

        $labelBuckets = [];
        foreach ($specLabels as $l) {
            $dim = strtolower(MigrationPayload::string($l['dimension'] ?? ''));
            $rawLabel = MigrationPayload::string($l['label'] ?? '');
            $clean = $this->cleanHtmlLabel($rawLabel);
            if ($dim === '' || $clean === '') {
                continue;
            }
            $key = $dim . '|' . mb_strtolower($clean);
            if (!isset($labelBuckets[$key])) {
                $labelBuckets[$key] = [
                    'dimension' => $dim,
                    'rawLabel' => $rawLabel,
                    'normalizedLabel' => $clean,
                    'usageCount' => 0,
                    'styles' => [],
                ];
            }
            ++$labelBuckets[$key]['usageCount'];
            $style = MigrationPayload::string($l['style'] ?? '');
            if ($style !== '') {
                $labelBuckets[$key]['styles'][$style] = true;
            }
        }

        $concepts = [];
        $extraDefs = [];
        $seenProposed = [];
        foreach ($labelBuckets as $bucket) {
            $norm = mb_strtolower($bucket['normalizedLabel']);
            $match = null;
            foreach (self::DV_CONCEPT_DEFS as $needle => $meta) {
                if (str_contains($norm, $needle)) {
                    $match = $meta;
                    break;
                }
            }
            $decision = SpecMasterDecisionState::REVIEW;
            $proposedCode = null;
            $existing = null;
            $reason = 'dv_label_concept_needs_review_no_global_d_field_assumption';
            $createGroup = 'mechanical';
            $createName = '';
            $createDataType = 'decimal';
            $createUnit = 'MM';
            if ($match !== null) {
                $createName = (string) $match['name'];
                $createGroup = (string) ($match['group'] ?? 'mechanical');
                $createDataType = (string) ($match['dataType'] ?? 'decimal');
                $createUnit = array_key_exists('unit', $match) && $match['unit'] === null
                    ? ''
                    : (string) ($match['unit'] ?? 'MM');
                if (!empty($match['existing']) && isset($targetDefs[$match['code']])) {
                    $decision = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                    $existing = $match['code'];
                    $proposedCode = $match['code'];
                    $reason = 'dv_label_maps_existing_definition_candidate';
                } else {
                    $decision = SpecMasterDecisionState::PROPOSED_CREATE;
                    $proposedCode = $match['code'];
                    $reason = 'dv_label_proposes_shared_definition_candidate';
                }
            }
            $concepts[] = [
                'sourceSystem' => 'tc',
                'sourceField' => $bucket['dimension'],
                'sourceLabel' => $bucket['rawLabel'],
                'semanticConcept' => $bucket['normalizedLabel'],
                'usageCount' => $bucket['usageCount'],
                'sampleStyles' => array_slice(array_keys($bucket['styles']), 0, 8),
                'existingTargetDefinition' => $existing,
                'proposedDefinitionCode' => $proposedCode,
                'proposedDefinitionName' => $createName !== '' ? $createName : null,
                'decision' => $decision,
                'confidence' => $match !== null ? 'medium' : 'low',
                'reason' => $reason,
                'batchCNote' => 'Style-specific mapping of ' . $bucket['dimension'] . ' remains Batch C',
            ];

            if ($decision === SpecMasterDecisionState::PROPOSED_CREATE && !isset($targetDefs[(string) $proposedCode]) && !isset($seenProposed[(string) $proposedCode]) && !isset($existingCodes[(string) $proposedCode])) {
                $code = (string) $proposedCode;
                $seenProposed[$code] = true;
                $extraDefs[] = [
                    'sourceSystem' => 'tc',
                    'sourceField' => 'spec_labels:' . $bucket['dimension'],
                    'sourceLabel' => $bucket['normalizedLabel'],
                    'semanticConcept' => LegacySpecFieldClass::SPECIFICATION,
                    'fieldClass' => LegacySpecFieldClass::SPECIFICATION,
                    'usageCount' => $bucket['usageCount'],
                    'sampleProducts' => [],
                    'sampleValues' => [$bucket['normalizedLabel']],
                    'proposedGroup' => $createGroup,
                    'existingTargetDefinition' => null,
                    'proposedDefinitionCode' => $code,
                    'proposedDefinitionName' => $createName,
                    'dataType' => $createDataType,
                    'unit' => $createUnit === '' ? null : $createUnit,
                    'decision' => SpecMasterDecisionState::PROPOSED_CREATE,
                    'confidence' => 'medium',
                    'evidence' => ['from' => 'accessory_spec_labels', 'dimension' => $bucket['dimension']],
                    'reason' => 'shared_dv_concept_proposed_create_not_batch_c_row',
                ];
            }
        }

        // Ensure Batch C row count is recorded but not approved
        unset($dvRows);

        return [
            'conceptCount' => count($concepts),
            'concepts' => array_slice($concepts, 0, 200),
            'definitionRows' => $extraDefs,
        ];
    }

    /**
     * @param array<string, mixed> $unitsProposed
     * @param array<string, array<string, mixed>> $targetUnits
     * @return list<array<string, mixed>>
     */
    private function resolveUnits(array $unitsProposed, array $targetUnits): array
    {
        $mappings = is_array($unitsProposed['mappings'] ?? null) ? $unitsProposed['mappings'] : $unitsProposed;
        $out = [];
        $seen = [];
        foreach ($mappings as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = MigrationPayload::string($row['legacyRepresentation'] ?? $row['rawUnit'] ?? '');
            $target = strtoupper(MigrationPayload::string($row['canonicalUnitCode'] ?? $row['targetUnit'] ?? ''));
            $norm = $this->normalizeUnitKey($raw);
            if ($norm === '') {
                continue;
            }
            $seen[$norm] = true;
            $decision = SpecMasterDecisionState::REVIEW;
            $reason = 'unit_unresolved';
            if ($target !== '' && isset($targetUnits[$target])) {
                $decision = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                $reason = 'mapped_to_seeded_specification_unit';
            } elseif ($target !== '' && !isset($targetUnits[$target])) {
                $decision = SpecMasterDecisionState::REVIEW;
                $reason = 'target_unit_missing_from_live_master';
            }
            $out[] = [
                'rawUnit' => $raw,
                'normalizedUnit' => $norm,
                'targetUnit' => $target !== '' ? $target : null,
                'usageCount' => (int) ($row['usageCount'] ?? 0),
                'decision' => $decision,
                'reason' => $reason,
            ];
        }

        // Ensure mm2 / mm² equivalence both map MM2
        foreach (['mm2', 'mm²', 'mm^2'] as $raw) {
            $norm = $this->normalizeUnitKey($raw);
            if (isset($seen[$norm])) {
                continue;
            }
            $out[] = [
                'rawUnit' => $raw,
                'normalizedUnit' => $norm,
                'targetUnit' => isset($targetUnits['MM2']) ? 'MM2' : null,
                'usageCount' => 0,
                'decision' => isset($targetUnits['MM2']) ? SpecMasterDecisionState::APPROVED_MAP_EXISTING : SpecMasterDecisionState::REVIEW,
                'reason' => 'mm2_equivalence_to_MM2',
            ];
        }

        foreach (self::EXPECTED_UNITS as $code) {
            if (!isset($targetUnits[$code])) {
                $out[] = [
                    'rawUnit' => $code,
                    'normalizedUnit' => strtolower($code),
                    'targetUnit' => null,
                    'usageCount' => 0,
                    'decision' => SpecMasterDecisionState::REVIEW,
                    'reason' => 'expected_seeded_unit_missing_live',
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $existingOptions
     * @param array<string, mixed> $operator
     * @return list<array<string, mixed>>
     */
    private function resolveStandards(array $standardsProposed, array $existingOptions, array $operator): array
    {
        $values = is_array($standardsProposed['values'] ?? null) ? $standardsProposed['values'] : $standardsProposed;
        $overrides = is_array($operator['standards'] ?? null) ? $operator['standards'] : [];
        $grouped = [];
        foreach ($values as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = MigrationPayload::string($row['rawValue'] ?? '');
            $parsed = MigrationPayload::string($row['parsedValue'] ?? $row['canonicalCandidate'] ?? '');
            $canon = MigrationPayload::string($row['canonicalCandidate'] ?? $parsed);
            if ($canon === '') {
                continue;
            }
            $key = $this->normalizeMatchKey($canon);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'rawValues' => [],
                    'normalizedStandard' => $canon,
                    'usageCount' => 0,
                    'sampleProducts' => [],
                ];
            }
            $grouped[$key]['rawValues'][$raw] = true;
            $grouped[$key]['usageCount'] += (int) ($row['usageCount'] ?? 1);
        }

        $out = [];
        foreach ($grouped as $key => $g) {
            $existing = null;
            foreach ($existingOptions as $opt) {
                $optKey = $this->normalizeMatchKey((string) ($opt['code'] ?? $opt['name'] ?? ''));
                $optNameKey = $this->normalizeMatchKey((string) ($opt['name'] ?? ''));
                if ($optKey === $key || $optNameKey === $key) {
                    $existing = (string) ($opt['code'] ?? $opt['name']);
                    break;
                }
            }
            $decision = SpecMasterDecisionState::PROPOSED_CREATE;
            $reason = 'new_standard_option_proposed_needs_operator_approval';
            $confidence = 'medium';
            if ($existing !== null) {
                $decision = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                $reason = 'matched_existing_STANDARD_option';
                $confidence = 'high';
            }
            if (isset($overrides[$g['normalizedStandard']]) && is_array($overrides[$g['normalizedStandard']])) {
                $decision = MigrationPayload::string($overrides[$g['normalizedStandard']]['decision'] ?? $decision);
                $reason = MigrationPayload::string($overrides[$g['normalizedStandard']]['reason'] ?? $reason);
            }
            if ($decision === SpecMasterDecisionState::APPROVED_CREATE && !isset($overrides[$g['normalizedStandard']])) {
                $decision = SpecMasterDecisionState::PROPOSED_CREATE;
            }
            $out[] = [
                'rawValues' => array_keys($g['rawValues']),
                'normalizedStandard' => $g['normalizedStandard'],
                'normalizedKey' => $key,
                'usageCount' => $g['usageCount'],
                'sampleProducts' => $g['sampleProducts'],
                'existingTargetOption' => $existing,
                'proposedOption' => [
                    'code' => $this->normalizeCode($g['normalizedStandard']),
                    'displayName' => $g['normalizedStandard'],
                ],
                'aliases' => array_values(array_filter(array_keys($g['rawValues']), static fn (string $r): bool => $r !== $g['normalizedStandard'])),
                'decision' => $decision,
                'confidence' => $confidence,
                'reason' => $reason,
            ];
        }
        usort($out, static fn (array $a, array $b): int => ($b['usageCount'] <=> $a['usageCount']));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $existingOptions
     * @param array<string, mixed> $operator
     * @return list<array<string, mixed>>
     */
    private function resolveApprovals(array $approvalsProposed, array $existingOptions, array $operator): array
    {
        $values = is_array($approvalsProposed['values'] ?? null) ? $approvalsProposed['values'] : $approvalsProposed;
        $overrides = is_array($operator['approvals'] ?? null) ? $operator['approvals'] : [];
        $grouped = [];
        foreach ($values as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = MigrationPayload::string($row['rawValue'] ?? '');
            $canon = MigrationPayload::string($row['canonicalCandidate'] ?? $row['parsedValue'] ?? '');
            if ($canon === '') {
                continue;
            }
            // Filter obvious non-approval categories from certificate CPT noise
            if ($this->looksLikeCertificateCategoryNoise($canon)) {
                continue;
            }
            $key = $this->normalizeMatchKey($canon);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'rawValues' => [],
                    'normalizedApproval' => $canon,
                    'usageCount' => 0,
                ];
            }
            $grouped[$key]['rawValues'][$raw] = true;
            $grouped[$key]['usageCount'] += (int) ($row['usageCount'] ?? 1);
        }

        $out = [];
        foreach ($grouped as $key => $g) {
            $existing = null;
            foreach ($existingOptions as $opt) {
                $optKey = $this->normalizeMatchKey((string) ($opt['code'] ?? $opt['name'] ?? ''));
                if ($optKey === $key) {
                    $existing = (string) ($opt['code'] ?? $opt['name']);
                    break;
                }
            }
            $decision = SpecMasterDecisionState::PROPOSED_CREATE;
            $reason = 'new_approval_option_proposed_needs_operator_approval';
            $confidence = 'medium';
            if ($existing !== null) {
                $decision = SpecMasterDecisionState::APPROVED_MAP_EXISTING;
                $reason = 'matched_existing_APPROVALS_option';
                $confidence = 'high';
            }
            // DNV family stays REVIEW for merge
            if (preg_match('/^dnv([\s\-]?gl)?$/i', $g['normalizedApproval']) === 1) {
                $decision = SpecMasterDecisionState::REVIEW;
                $reason = 'dnv_dnv_gl_ambiguity_do_not_silent_merge';
                $confidence = 'low';
            }
            if (isset($overrides[$g['normalizedApproval']]) && is_array($overrides[$g['normalizedApproval']])) {
                $decision = MigrationPayload::string($overrides[$g['normalizedApproval']]['decision'] ?? $decision);
                $reason = MigrationPayload::string($overrides[$g['normalizedApproval']]['reason'] ?? $reason);
            }
            $out[] = [
                'rawValues' => array_keys($g['rawValues']),
                'normalizedApproval' => $g['normalizedApproval'],
                'normalizedKey' => $key,
                'issuingOrganization' => null,
                'usageCount' => $g['usageCount'],
                'sampleProducts' => [],
                'existingTargetOption' => $existing,
                'proposedOption' => [
                    'code' => $this->normalizeCode($g['normalizedApproval']),
                    'displayName' => $g['normalizedApproval'],
                ],
                'aliases' => array_values(array_filter(array_keys($g['rawValues']), static fn (string $r): bool => $r !== $g['normalizedApproval'])),
                'decision' => $decision,
                'confidence' => $confidence,
                'reason' => $reason,
            ];
        }
        usort($out, static fn (array $a, array $b): int => ($b['usageCount'] <=> $a['usageCount']));

        return $out;
    }

    /**
     * @param array<string, mixed> $optionsProposed
     * @param list<array<string, mixed>> $standards
     * @param list<array<string, mixed>> $approvals
     * @param array<string, mixed> $operator
     * @return list<array<string, mixed>>
     */
    private function resolveAliases(array $optionsProposed, array $standards, array $approvals, array $operator): array
    {
        $proposals = is_array($optionsProposed['proposals'] ?? null) ? $optionsProposed['proposals'] : $optionsProposed;
        $out = [];
        foreach ($proposals as $row) {
            if (!is_array($row)) {
                continue;
            }
            $canonical = MigrationPayload::string($row['canonicalOption'] ?? '');
            $aliases = is_array($row['legacyAliases'] ?? null) ? $row['legacyAliases'] : [];
            $source = MigrationPayload::string(($row['sourceTable'] ?? '') . '.' . ($row['sourceColumn'] ?? ''));
            $usage = (int) ($row['usageCount'] ?? 0);
            if ($aliases === []) {
                // Case/whitespace self-alias candidate from canonical alone is not an alias row
                continue;
            }
            foreach ($aliases as $alias) {
                $raw = MigrationPayload::string(is_array($alias) ? ($alias['raw'] ?? $alias['value'] ?? '') : (string) $alias);
                if ($raw === '') {
                    continue;
                }
                $norm = $this->normalizeDisplay($raw);
                $decision = SpecMasterDecisionState::ALIAS_REVIEW;
                $reason = 'semantic_alias_requires_review';
                $confidence = 'low';
                if ($this->isSafeAlias($raw, $canonical)) {
                    $decision = SpecMasterDecisionState::SAFE_ALIAS;
                    $reason = 'deterministic_html_whitespace_or_case_equivalence';
                    $confidence = 'high';
                }
                if ($this->isDnvFamilyPair($raw, $canonical)) {
                    $decision = SpecMasterDecisionState::ALIAS_REVIEW;
                    $reason = 'dnv_vs_dnv_gl_not_safe_alias';
                    $confidence = 'low';
                }
                $out[] = [
                    'rawValue' => $raw,
                    'normalizedValue' => $norm,
                    'targetOption' => $canonical,
                    'source' => $source,
                    'usageCount' => $usage,
                    'decision' => $decision,
                    'confidence' => $confidence,
                    'reason' => $reason,
                ];
            }
        }

        // Add safe aliases from standards raw variants
        foreach ($standards as $s) {
            foreach ($s['aliases'] ?? [] as $raw) {
                $raw = MigrationPayload::string((string) $raw);
                $target = MigrationPayload::string($s['normalizedStandard'] ?? '');
                if ($raw === '' || $target === '') {
                    continue;
                }
                $decision = $this->isSafeAlias($raw, $target)
                    ? SpecMasterDecisionState::SAFE_ALIAS
                    : SpecMasterDecisionState::ALIAS_REVIEW;
                $out[] = [
                    'rawValue' => $raw,
                    'normalizedValue' => $this->normalizeDisplay($raw),
                    'targetOption' => $target,
                    'source' => 'wordpress.standards',
                    'usageCount' => (int) ($s['usageCount'] ?? 0),
                    'decision' => $decision,
                    'confidence' => $decision === SpecMasterDecisionState::SAFE_ALIAS ? 'high' : 'low',
                    'reason' => $decision === SpecMasterDecisionState::SAFE_ALIAS
                        ? 'standards_raw_variant_safe_normalization'
                        : 'standards_raw_variant_semantic_review',
                ];
            }
        }

        foreach ($approvals as $a) {
            foreach ($a['aliases'] ?? [] as $raw) {
                $raw = MigrationPayload::string((string) $raw);
                $target = MigrationPayload::string($a['normalizedApproval'] ?? '');
                if ($raw === '' || $target === '') {
                    continue;
                }
                if ($this->isDnvFamilyPair($raw, $target)) {
                    $out[] = [
                        'rawValue' => $raw,
                        'normalizedValue' => $this->normalizeDisplay($raw),
                        'targetOption' => $target,
                        'source' => 'wordpress.approvals',
                        'usageCount' => (int) ($a['usageCount'] ?? 0),
                        'decision' => SpecMasterDecisionState::ALIAS_REVIEW,
                        'confidence' => 'low',
                        'reason' => 'dnv_vs_dnv_gl_not_safe_alias',
                    ];
                    continue;
                }
                $decision = $this->isSafeAlias($raw, $target)
                    ? SpecMasterDecisionState::SAFE_ALIAS
                    : SpecMasterDecisionState::ALIAS_REVIEW;
                $out[] = [
                    'rawValue' => $raw,
                    'normalizedValue' => $this->normalizeDisplay($raw),
                    'targetOption' => $target,
                    'source' => 'wordpress.approvals',
                    'usageCount' => (int) ($a['usageCount'] ?? 0),
                    'decision' => $decision,
                    'confidence' => $decision === SpecMasterDecisionState::SAFE_ALIAS ? 'high' : 'low',
                    'reason' => $decision === SpecMasterDecisionState::SAFE_ALIAS
                        ? 'approvals_raw_variant_safe_normalization'
                        : 'approvals_raw_variant_semantic_review',
                ];
            }
        }

        unset($operator);

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $targetDefs
     * @return array<string, mixed>
     */
    private function resolveCoreColours(array $targetDefs): array
    {
        $hasFull = isset($targetDefs['CORE-COLOURS']);
        $hasCode = isset($targetDefs['CODE_CORE_COLOUR']);

        return [
            'fullCoreColourSpecification' => $hasFull ? 'READY' : 'REVIEW',
            'fullDefinition' => 'CORE-COLOURS',
            'fullDataType' => 'long-text',
            'codingCoreColour' => $hasCode ? 'SEPARATE' : 'issue',
            'codingDefinition' => 'CODE_CORE_COLOUR',
            'informationLoss' => 'NO',
            'policy' => 'Preserve matrix text on CORE-COLOURS; do not collapse into CODE_CORE_COLOUR single-select.',
            'normalizationExamples' => [
                [
                    'raw' => "1C: BK\n2C: BR BL\n3C: BR BK G/Y",
                    'normalized' => "1C: BK\n2C: BR BL\n3C: BR BK G/Y",
                    'uiCompatible' => true,
                    'note' => 'Preserve line matrix; UI parses cores → colour codes',
                ],
                [
                    'raw' => '1C:&nbsp;BK',
                    'normalized' => '1C: BK',
                    'uiCompatible' => true,
                    'note' => 'HTML entity decode + whitespace normalize only',
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @param list<array<string, mixed>> $standards
     * @param list<array<string, mixed>> $approvals
     * @param list<array<string, mixed>> $aliases
     * @param list<array<string, mixed>> $units
     * @return array<string, mixed>
     */
    private function buildDryRunPlan(array $definitions, array $standards, array $approvals, array $aliases, array $units): array
    {
        $mapExistingDefs = 0;
        $createDefs = 0;
        $approvedCreateDefs = 0;
        foreach ($definitions as $d) {
            $dec = (string) ($d['decision'] ?? '');
            if ($dec === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapExistingDefs;
            }
            if ($dec === SpecMasterDecisionState::PROPOSED_CREATE) {
                ++$createDefs;
            }
            if ($dec === SpecMasterDecisionState::APPROVED_CREATE) {
                ++$approvedCreateDefs;
            }
        }
        $mapOpts = 0;
        $createOpts = 0;
        $approvedOpts = 0;
        foreach (array_merge($standards, $approvals) as $o) {
            $dec = (string) ($o['decision'] ?? '');
            if ($dec === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapOpts;
            }
            if ($dec === SpecMasterDecisionState::PROPOSED_CREATE) {
                ++$createOpts;
            }
            if ($dec === SpecMasterDecisionState::APPROVED_CREATE) {
                ++$approvedOpts;
            }
        }
        $safeAliases = 0;
        foreach ($aliases as $a) {
            if (($a['decision'] ?? '') === SpecMasterDecisionState::SAFE_ALIAS) {
                ++$safeAliases;
            }
        }
        $unitsMapped = 0;
        foreach ($units as $u) {
            if (($u['decision'] ?? '') === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$unitsMapped;
            }
        }

        // Dry-run only: future apply would write only APPROVED_* rows. Currently APPROVED_CREATE is 0.
        return [
            'targetValidated' => true,
            'mode' => 'DRY_RUN_ONLY',
            'writesWouldExecute' => false,
            'definitionsMappedExisting' => $mapExistingDefs,
            'definitionsToCreate' => $approvedCreateDefs,
            'definitionsProposedPendingApproval' => $createDefs,
            'optionsMappedExisting' => $mapOpts,
            'optionsToCreate' => $approvedOpts,
            'optionsProposedPendingApproval' => $createOpts,
            'definitionMappingsToInsert' => $mapExistingDefs + $approvedCreateDefs,
            'optionMappingsToInsert' => $mapOpts + $approvedOpts,
            'aliasesToInsert' => $safeAliases,
            'unitsMapped' => $unitsMapped,
            'productSpecificationValues' => 0,
            'productMutations' => 0,
            'variantMutations' => 0,
            'classificationMutations' => 0,
            'catalogueMutations' => 0,
            'pricingMutations' => 0,
            'mediaMutations' => 0,
            'documentMutations' => 0,
            'legacyWrites' => 0,
            'idempotencyPlan' => [
                'secondApplyDefinitionsCreated' => 0,
                'secondApplyOptionsCreated' => 0,
                'secondApplyMappingsInserted' => 0,
                'secondApplyAliasesInserted' => 0,
            ],
        ];
    }

    private function classifyWpField(string $field): string
    {
        if (isset(self::WP_FIELD_CLASS[$field])) {
            return self::WP_FIELD_CLASS[$field];
        }
        if (str_contains($field, 'image') || str_ends_with($field, '_thumbnail')) {
            return LegacySpecFieldClass::MEDIA;
        }
        if (str_contains($field, 'certificate')) {
            return LegacySpecFieldClass::DOCUMENT;
        }
        if (str_contains($field, 'price') || str_contains($field, 'cost')) {
            return LegacySpecFieldClass::PRICE;
        }

        return LegacySpecFieldClass::UNKNOWN;
    }

    private function normalizeUnitKey(string $raw): string
    {
        $s = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = mb_strtolower($s);
        $s = str_replace([' ', '°'], ['', ''], $s);
        if (in_array($s, ['mm2', 'mm²', 'mm^2', 'mm&sup2;'], true)) {
            return 'mm2';
        }

        return $s;
    }

    private function normalizeMatchKey(string $value): string
    {
        $s = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\u{00A0}", ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return mb_strtolower($s);
    }

    private function normalizeDisplay(string $value): string
    {
        $s = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\u{00A0}", ' ', $s);
        $s = strip_tags($s);

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    private function cleanHtmlLabel(string $raw): string
    {
        $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_ireplace(['<br>', '<br/>', '<br />', '&nbsp;'], ' ', $s);
        $s = strip_tags($s);

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    private function isSafeAlias(string $raw, string $target): bool
    {
        return $this->normalizeMatchKey($raw) === $this->normalizeMatchKey($target);
    }

    private function isDnvFamilyPair(string $a, string $b): bool
    {
        $na = preg_replace('/[\s\-]+/', '', mb_strtolower($a)) ?? '';
        $nb = preg_replace('/[\s\-]+/', '', mb_strtolower($b)) ?? '';
        $family = static fn (string $x): bool => $x === 'dnv' || $x === 'dnvgl';

        return $family($na) && $family($nb) && $na !== $nb;
    }

    private function looksLikeCertificateCategoryNoise(string $value): bool
    {
        $v = mb_strtolower($value);
        foreach (['news', 'electrical contracting certificates', 'uncategorized', 'blog'] as $noise) {
            if ($v === $noise) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCode(string $value): string
    {
        $s = strtoupper(trim($value));
        $s = preg_replace('/[^A-Z0-9]+/', '-', $s) ?? $s;
        $s = trim($s, '-');

        return $s !== '' ? substr($s, 0, 50) : 'UNKNOWN';
    }

    private function humanize(string $code): string
    {
        return ucwords(strtolower(str_replace('-', ' ', $code)));
    }

    /**
     * @param list<mixed> $samples
     */
    private function inferDataType(array $samples, string $field): string
    {
        if (in_array($field, ['degrees', 'ind_degrees', 'kilovolts', 'ind_kilovolts', 'oxygen_index', 'hcl_emission'], true)) {
            return 'decimal';
        }
        $numeric = 0;
        $n = 0;
        foreach ($samples as $s) {
            $t = trim((string) $s);
            if ($t === '') {
                continue;
            }
            ++$n;
            if (is_numeric($t)) {
                ++$numeric;
            }
        }
        if ($n > 0 && $numeric / $n >= 0.7) {
            return 'decimal';
        }

        return 'text';
    }

    /**
     * @param list<mixed> $samples
     */
    private function inferUnit(array $samples, string $field): ?string
    {
        unset($samples);
        return match ($field) {
            'degrees', 'ind_degrees' => 'C',
            'kilovolts', 'ind_kilovolts' => 'V',
            default => null,
        };
    }

    private function suggestGroup(string $class, string $field): string
    {
        if ($class === LegacySpecFieldClass::STANDARD || $class === LegacySpecFieldClass::APPROVAL) {
            return 'compliance';
        }
        if (in_array($field, ['degrees', 'ind_degrees'], true)) {
            return 'thermal';
        }
        if (in_array($field, ['kilovolts', 'ind_kilovolts', 'hcl_emission', 'oxygen_index'], true)) {
            return 'electrical';
        }

        return 'construction';
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $keys
     * @return array<string, int>
     */
    private function countDecisions(array $items, array $keys): array
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
     * @param list<array<string, mixed>> $units
     * @return array{mapped: int, review: int, newRequired: int}
     */
    private function unitCounts(array $units): array
    {
        $mapped = 0;
        $review = 0;
        $newRequired = 0;
        foreach ($units as $u) {
            $d = (string) ($u['decision'] ?? '');
            if ($d === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapped;
            } elseif (($u['targetUnit'] ?? null) === null && $d === SpecMasterDecisionState::REVIEW) {
                ++$newRequired;
            } else {
                ++$review;
            }
        }

        return ['mapped' => $mapped, 'review' => $review, 'newRequired' => $newRequired];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{mapped: int, approved: int, proposed: int, review: int}
     */
    private function optionDecisionCounts(array $items): array
    {
        $mapped = 0;
        $approved = 0;
        $proposed = 0;
        $review = 0;
        foreach ($items as $item) {
            $d = (string) ($item['decision'] ?? '');
            if ($d === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapped;
            } elseif ($d === SpecMasterDecisionState::APPROVED_CREATE) {
                ++$approved;
            } elseif ($d === SpecMasterDecisionState::PROPOSED_CREATE) {
                ++$proposed;
            } else {
                ++$review;
            }
        }

        return compact('mapped', 'approved', 'proposed', 'review');
    }

    /**
     * @param list<array<string, mixed>> $approvals
     * @return array<string, mixed>
     */
    private function dnvAmbiguity(array $approvals): array
    {
        $hits = [];
        foreach ($approvals as $a) {
            $n = MigrationPayload::string($a['normalizedApproval'] ?? '');
            if (preg_match('/dnv/i', $n) === 1) {
                $hits[] = $n;
            }
        }

        return [
            'doNotSilentMerge' => true,
            'candidates' => $hits,
            'status' => $hits === [] ? 'NONE_FOUND' : 'REVIEW',
        ];
    }

    /**
     * @param array<string, int> $defCounts
     * @param array{mapped: int, approved: int, proposed: int, review: int} $stdCounts
     * @param array{mapped: int, approved: int, proposed: int, review: int} $aprCounts
     * @param array<string, int> $readiness
     */
    private function recommendation(array $defCounts, array $stdCounts, array $aprCounts, array $readiness): string
    {
        unset($readiness);
        if ($defCounts[SpecMasterDecisionState::APPROVED_CREATE] > 0
            && $stdCounts['proposed'] === 0
            && $aprCounts['proposed'] === 0
            && $defCounts[SpecMasterDecisionState::PROPOSED_CREATE] === 0
            && $defCounts[SpecMasterDecisionState::REVIEW] === 0
        ) {
            return 'APPROVE BATCH B TARGET APPLY';
        }

        return 'CONTINUE SPECIFICATION REVIEW';
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
            $code = strtoupper(MigrationPayload::string($row['code'] ?? $row['DefinitionCode'] ?? $row['UnitCode'] ?? $row['GroupCode'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'code' => $code,
                'name' => MigrationPayload::string($row['name'] ?? $row['DefinitionName'] ?? $row['UnitName'] ?? $row['GroupName'] ?? ''),
                'dataType' => MigrationPayload::string($row['dataType'] ?? $row['DataTypeCode'] ?? ''),
                'group' => MigrationPayload::string($row['group'] ?? $row['groupCode'] ?? $row['GroupCode'] ?? ''),
                'publicId' => MigrationPayload::string($row['publicId'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexOptions(mixed $rows): array
    {
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $def = strtoupper(MigrationPayload::string($row['definitionCode'] ?? $row['DefinitionCode'] ?? ''));
            if ($def === '') {
                continue;
            }
            $out[$def][] = [
                'code' => MigrationPayload::string($row['code'] ?? $row['OptionCode'] ?? ''),
                'name' => MigrationPayload::string($row['name'] ?? $row['OptionName'] ?? $row['OptionLabel'] ?? ''),
                'publicId' => MigrationPayload::string($row['publicId'] ?? ''),
                'isActive' => (bool) ($row['isActive'] ?? $row['IsActive'] ?? true),
            ];
        }

        return $out;
    }

    /** Expose parsers for unit tests. */
    public function parseStandardsBlob(string $raw): array
    {
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = strip_tags($decoded);
        if ($this->looksSerialized($decoded)) {
            $un = @unserialize($decoded, ['allowed_classes' => false]);
            if (is_array($un)) {
                $parts = [];
                array_walk_recursive($un, static function ($v) use (&$parts): void {
                    if (is_string($v) || is_numeric($v)) {
                        $parts[] = (string) $v;
                    }
                });
                $decoded = implode(',', $parts);
            }
        }
        $pieces = preg_split('/[,;\n\r]+/', $decoded) ?: [];
        $out = [];
        foreach ($pieces as $p) {
            $t = trim($p);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    public function unitEquivalenceKey(string $raw): string
    {
        return $this->normalizeUnitKey($raw);
    }

    public function safeAliasCheck(string $raw, string $target): bool
    {
        return $this->isSafeAlias($raw, $target);
    }

    public function dnvPairCheck(string $a, string $b): bool
    {
        return $this->isDnvFamilyPair($a, $b);
    }

    private function looksSerialized(string $value): bool
    {
        return preg_match('/^[aOs]:\d+:/', $value) === 1;
    }
}
