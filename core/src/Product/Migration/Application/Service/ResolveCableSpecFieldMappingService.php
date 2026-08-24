<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Cable Specification Field Mapping — dry-run resolve only.
 * Never creates Definitions/Options/Units or inserts field mappings / values.
 */
final class ResolveCableSpecFieldMappingService
{
    public const PROPOSED_MAP_EXISTING = 'PROPOSED_MAP_EXISTING';
    public const PROPOSED_CREATE = 'PROPOSED_CREATE';
    public const REVIEW = 'REVIEW';
    public const NOT_A_SPECIFICATION = 'NOT_A_SPECIFICATION';
    public const REJECT = 'REJECT';

    private const EXPECTED_CABLE_PRODUCTS = 54;
    private const EXPECTED_DEFINITIONS = 55;
    private const EXPECTED_STYLE_FIELD_MAPPINGS = 483;
    private const EXPECTED_FIELD_MAPPINGS = 506;
    private const ACCESSORY_SPEC_FIELD_MAPPING_READY = 27;

    /**
     * Field catalog: decision + optional target proposal metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fieldCatalog(): array
    {
        return [
            'cable_style' => [
                'decision' => self::NOT_A_SPECIFICATION,
                'reason' => 'identity_style_key',
                'concept' => 'IDENTITY',
            ],
            'my_row_id' => [
                'decision' => self::NOT_A_SPECIFICATION,
                'reason' => 'surrogate_primary_key',
                'concept' => 'IDENTITY',
            ],
            'height' => [
                'decision' => self::NOT_A_SPECIFICATION,
                'reason' => 'unused_in_cable_population',
                'concept' => 'UNUSED',
            ],
            'width' => [
                'decision' => self::NOT_A_SPECIFICATION,
                'reason' => 'unused_in_cable_population',
                'concept' => 'UNUSED',
            ],
            'A15c' => [
                'decision' => self::NOT_A_SPECIFICATION,
                'reason' => 'unused_in_cable_population',
                'concept' => 'UNUSED',
            ],
            'A25c' => [
                'decision' => self::NOT_A_SPECIFICATION,
                'reason' => 'unused_in_cable_population',
                'concept' => 'UNUSED',
            ],
            'cap' => [
                'decision' => self::NOT_A_SPECIFICATION,
                'reason' => 'unused_in_cable_population',
                'concept' => 'UNUSED',
            ],
            'core_con' => [
                'decision' => self::NOT_A_SPECIFICATION,
                'reason' => 'unused_in_cable_population',
                'concept' => 'UNUSED',
            ],
            'nc' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'CORES',
                'name' => 'Number of cores',
                'group' => 'construction',
                'dataType' => 'integer',
                'unit' => null,
                'reason' => 'integer_cores',
                'concept' => 'CORES',
                'note' => 'Integer core count (nc).',
            ],
            'xa' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'CSA',
                'name' => 'Conductor CSA',
                'group' => 'construction',
                'dataType' => 'text',
                'unit' => 'mm2',
                'reason' => 'conductor_csa_mm2',
                'concept' => 'CSA',
                'note' => 'Conductor CSA (mm²).',
            ],
            'od' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'NOM-CABLE-OD',
                'name' => 'Nom. Cable',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'cable_od_mm_maps_to_nom_cable_od',
                'concept' => 'NOM_CABLE_OD',
                'note' => 'Cable OD mm → NOM-CABLE-OD. Accessory OUTER-DIAMETER is distinct and must never be aliased here.',
                'neverMapTo' => 'OUTER-DIAMETER',
            ],
            'cdia' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'NOM-COND-OD',
                'name' => 'Nom. Cond. OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'conductor_od_mm',
                'concept' => 'NOM_COND_OD',
            ],
            'od_ins' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'NOM-INS-OD',
                'name' => 'Nom. Ins. OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'insulation_od_mm',
                'concept' => 'NOM_INS_OD',
            ],
            'br' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'MIN-BEND-RADIUS',
                'name' => 'Min. Bend Radius',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'min_bend_radius',
                'concept' => 'MIN_BEND_RADIUS',
            ],
            'wght' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'APPROX-WEIGHT',
                'name' => 'Approx. Wt.',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'kg/km',
                'reason' => 'approx_weight_kg_per_km',
                'concept' => 'APPROX_WEIGHT',
            ],
            'A30c' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'CURRENT',
                'name' => 'Current',
                'group' => 'electrical',
                'dataType' => 'decimal',
                'unit' => 'A',
                'reason' => 'current_rating_ambient_30c',
                'concept' => 'CURRENT',
                'note' => 'Ambient 30°C current rating; shares CURRENT Definition with ambient note.',
            ],
            'A40c' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'CURRENT',
                'name' => 'Current',
                'group' => 'electrical',
                'dataType' => 'decimal',
                'unit' => 'A',
                'reason' => 'current_rating_ambient_40c',
                'concept' => 'CURRENT',
                'note' => 'Ambient 40°C current rating; shares CURRENT Definition with ambient note.',
            ],
            'A45c' => [
                'decision' => self::PROPOSED_MAP_EXISTING,
                'code' => 'CURRENT',
                'name' => 'Current',
                'group' => 'electrical',
                'dataType' => 'decimal',
                'unit' => 'A',
                'reason' => 'current_rating_ambient_45c',
                'concept' => 'CURRENT',
                'note' => 'Ambient 45°C current rating; shares CURRENT Definition with ambient note.',
            ],
            'ne' => [
                'decision' => self::PROPOSED_CREATE,
                'code' => 'EARTH-CORES',
                'name' => 'Earth cores',
                'group' => 'construction',
                'dataType' => 'integer',
                'unit' => null,
                'reason' => 'earth_core_count_mechanical_construction',
                'concept' => 'EARTH_CORES',
                'requiresPopulatedNonZero' => true,
            ],
            'xae' => [
                'decision' => self::PROPOSED_CREATE,
                'code' => 'EARTH-CSA',
                'name' => 'Earth CSA',
                'group' => 'construction',
                'dataType' => 'text',
                'unit' => 'mm2',
                'reason' => 'earth_conductor_csa_mm2',
                'concept' => 'EARTH_CSA',
                'requiresPopulatedNonZero' => true,
            ],
            'ode_ins' => [
                'decision' => self::PROPOSED_CREATE,
                'code' => 'EARTH-INS-OD',
                'name' => 'Earth insulation OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'earth_insulation_od_mm',
                'concept' => 'EARTH_INS_OD',
                'requiresPopulatedNonZero' => true,
            ],
            'od_bed' => [
                'decision' => self::PROPOSED_CREATE,
                'code' => 'BEDDING-OD',
                'name' => 'Bedding OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'bedding_od_mm',
                'concept' => 'BEDDING_OD',
                'requiresPopulatedNonZero' => true,
            ],
            'od_arm' => [
                'decision' => self::PROPOSED_CREATE,
                'code' => 'ARMOUR-OD',
                'name' => 'Armour OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'armour_od_mm',
                'concept' => 'ARMOUR_OD',
                'requiresPopulatedNonZero' => true,
            ],
            'od_brd' => [
                'decision' => self::PROPOSED_CREATE,
                'code' => 'BRAID-OD',
                'name' => 'Braid OD',
                'group' => 'mechanical',
                'dataType' => 'decimal',
                'unit' => 'mm',
                'reason' => 'braid_od_mm',
                'concept' => 'BRAID_OD',
                'requiresPopulatedNonZero' => true,
            ],
            'cc' => [
                'decision' => self::REVIEW,
                'reason' => 'UNKNOWN_SEMANTICS',
                'concept' => 'CONSTRUCTION_CODE',
                'note' => 'c/p/t/a construction codes — option ambiguity.',
            ],
            'ins' => [
                'decision' => self::REVIEW,
                'reason' => 'TYPE_AMBIGUITY',
                'concept' => 'INSULATION',
                'note' => 'Sparse; values may be thickness or OD.',
            ],
            'walu' => [
                'decision' => self::REVIEW,
                'reason' => 'UNKNOWN_SEMANTICS',
                'concept' => 'ALUMINIUM_WEIGHT',
                'note' => 'Aluminium weight — semantics unclear.',
            ],
            'wbnz' => [
                'decision' => self::REVIEW,
                'reason' => 'UNKNOWN_SEMANTICS',
                'concept' => 'BRONZE_WEIGHT',
                'note' => 'Bronze weight — semantics unclear.',
            ],
            'Af' => [
                'decision' => self::REVIEW,
                'reason' => 'INSUFFICIENT_VALUES',
                'concept' => 'CURRENT_AF',
                'note' => 'Only 1 style populated.',
            ],
            'r' => [
                'decision' => self::REVIEW,
                'reason' => 'INSUFFICIENT_VALUES',
                'concept' => 'RESISTANCE',
                'note' => 'Only 1 style populated.',
            ],
            'x50' => [
                'decision' => self::REVIEW,
                'reason' => 'INSUFFICIENT_VALUES',
                'concept' => 'REACTANCE_50',
                'note' => 'Only 1 style populated.',
            ],
            'x60' => [
                'decision' => self::REVIEW,
                'reason' => 'INSUFFICIENT_VALUES',
                'concept' => 'REACTANCE_60',
                'note' => 'Only 1 style populated.',
            ],
            'tension' => [
                'decision' => self::REVIEW,
                'reason' => 'INSUFFICIENT_VALUES',
                'concept' => 'TENSION',
                'note' => 'Only 1 style populated.',
            ],
            'mt' => [
                'decision' => self::REVIEW,
                'reason' => 'UNKNOWN_SEMANTICS',
                'concept' => 'MATERIAL_TYPE',
                'note' => 'Codes 1301/1302 — option ambiguity.',
            ],
            'ct' => [
                'decision' => self::REVIEW,
                'reason' => 'UNKNOWN_SEMANTICS',
                'concept' => 'CONDUCTOR_TYPE',
                'note' => 'p/a codes — option ambiguity.',
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
        $readyProducts = $this->normalizeProductList($inputs['classificationReadyProducts'] ?? []);
        $inventory = is_array($inputs['cableSpecInventory'] ?? null) ? $inputs['cableSpecInventory'] : [];
        $operator = is_array($inputs['operatorDecisions'] ?? null) ? $inputs['operatorDecisions'] : [];
        $forbiddenAttempts = (int) ($inputs['forbiddenWriteAttempts'] ?? 0);

        $cableProducts = $this->filterCableProducts($readyProducts);
        $discovered = count($cableProducts);
        $discrepancy = $discovered !== self::EXPECTED_CABLE_PRODUCTS;
        $populationStatus = $discrepancy ? 'BLOCKED_POPULATION_MISMATCH' : 'READY';
        $packageStatus = $discrepancy ? 'NOT_READY_FOR_OPERATOR_REVIEW' : 'READY_FOR_OPERATOR_REVIEW';

        $styleRowCounts = $this->indexStyleRowCounts($inventory['perStyleRows'] ?? []);
        $stats = is_array($inventory['stats'] ?? null) ? $inventory['stats'] : [];
        $columns = $this->normalizeColumns($inventory['columns'] ?? []);
        $rowCount = (int) ($inventory['rowCount'] ?? 0);

        $fieldDistributions = is_array($inputs['fieldDistributions'] ?? null)
            ? $inputs['fieldDistributions']
            : (is_array($inventory['fieldDistributions'] ?? null) ? $inventory['fieldDistributions'] : []);

        $decisions = $this->buildFieldDecisions($columns, $stats, $operator);
        $this->assertNeverProposesIndLszhFire($decisions);

        $fieldItems = $this->sortByField($this->buildFieldInventoryItems($decisions, $stats));
        $conceptItems = $this->sortByField($this->buildConceptDecisionItems($decisions));
        $conceptCounts = $this->countBy($conceptItems, 'decision');

        $proposedMappings = $this->buildProposedFieldMappings($decisions, $stats, $rowCount);
        $proposedDefinitions = $this->buildProposedDefinitions($decisions);

        $units = $this->buildUnitsSection($decisions);
        $options = $this->buildOptionsSection($decisions);
        $reviewReasons = $this->buildReviewReasons($decisions);
        $specialInvestigations = $this->buildSpecialInvestigations($stats, $fieldDistributions, $discovered);

        $readinessProducts = $this->evaluateProductReadiness($cableProducts, $styleRowCounts, $decisions, $stats);
        $readyCount = (int) ($readinessProducts['READY'] ?? 0);
        $reviewCount = (int) ($readinessProducts['REVIEW'] ?? 0);
        $blockedCount = (int) ($readinessProducts['BLOCKED'] ?? 0);

        $stylesMissing = [];
        foreach ($cableProducts as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            if ($style !== '' && ($styleRowCounts[$style] ?? 0) === 0) {
                $stylesMissing[] = $style;
            }
        }
        sort($stylesMissing);

        $identityMatched = 0;
        $identityBlocked = 0;
        foreach ($cableProducts as $p) {
            $status = strtoupper(MigrationPayload::string($p['tcIdentityStatus'] ?? ''));
            if ($status === 'BLOCKED' || $status === 'IDENTITY_BLOCKED') {
                ++$identityBlocked;
            } else {
                ++$identityMatched;
            }
        }

        $integrity = $this->buildBatchC2Integrity($azure);
        $definitionsToCreate = count($proposedDefinitions['items'] ?? []);
        $totalPopulated = 0;
        $variantLevel = 0;
        foreach ($fieldItems as $item) {
            $populated = (int) ($item['populated'] ?? 0);
            $totalPopulated += $populated;
            if (($item['scope'] ?? '') === 'VARIANT') {
                $variantLevel += $populated;
            }
        }

        $concepts = array_values(array_unique(array_map(
            static fn (array $d): string => (string) ($d['concept'] ?? 'UNKNOWN'),
            $decisions,
        )));
        sort($concepts);

        $odDecision = $decisions['od'] ?? [];
        $odCode = strtoupper((string) ($odDecision['code'] ?? ''));
        $semanticGuards = [
            'odMapsToNomCableOd' => $odCode === 'NOM-CABLE-OD',
            'odNeverOuterDiameter' => ($odDecision['neverMapTo'] ?? null) === 'OUTER-DIAMETER'
                && $odCode !== 'OUTER-DIAMETER',
            'noIndLszhFire' => true,
        ];

        return [
            'phase' => '12.7-batch-d1-cable-spec-field-mapping',
            'mode' => 'DRY_RUN_ONLY',
            'packageStatus' => $packageStatus,
            'cableSpecApply' => 'NOT_APPROVED',
            'approvalBoundary' => [
                'cableSpecApplyGuard' => 'CABLE_SPEC_FIELD_MAPPING_APPLY_NOT_APPROVED',
                'noTargetWrites' => true,
                'noSpecificationValueWrites' => true,
                'noLegacyWrites' => true,
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => $forbiddenAttempts,
            ],
            'targetSafety' => [
                'definitionsCreated' => 0,
                'mappingsInserted' => 0,
                'specificationValuesWritten' => 0,
                'productsWritten' => 0,
                'variantsWritten' => 0,
                'unitsCreated' => 0,
                'optionsCreated' => 0,
            ],
            'target' => [
                'server' => MigrationPayload::string($azure['server'] ?? 'tricab-mel'),
                'database' => MigrationPayload::string($azure['database'] ?? 'tc'),
                'liveValidation' => MigrationPayload::string($azure['liveValidation'] ?? 'UNKNOWN'),
                'definitions' => (int) ($azure['definitionCount'] ?? count(is_array($azure['definitions'] ?? null) ? $azure['definitions'] : [])),
                'units' => (int) ($azure['unitCount'] ?? 0),
                'options' => (int) ($azure['optionCount'] ?? 0),
                'styleFieldMappingCount' => (int) ($azure['styleFieldMappingCount'] ?? 0),
                'fieldMappingCount' => (int) ($azure['fieldMappingCount'] ?? 0),
            ],
            'sourceModel' => [
                'table' => 'cable_specs',
                'rowCount' => $rowCount,
                'columns' => $columns,
                'grain' => 'VARIANT',
                'wordpressRole' => 'not_source_for_this_phase',
                'note' => 'All cable_specs fields are VARIANT-level (row grain style+nc+xa+ct).',
            ],
            'cablePopulation' => [
                'expected' => self::EXPECTED_CABLE_PRODUCTS,
                'discovered' => $discovered,
                'identityMatched' => $identityMatched,
                'identityBlocked' => $identityBlocked,
                'stylesMissingCableSpecs' => $stylesMissing,
                'stylesMissingCableSpecsCount' => count($stylesMissing),
                'discrepancy' => $discrepancy,
                'status' => $populationStatus,
                'note' => $discrepancy
                    ? 'Expected exactly 54 classification-ready CABLE products; discovered ' . $discovered . '.'
                    : 'Classification-ready CABLE population matches expected 54.',
            ],
            'semanticGuards' => $semanticGuards,
            'fieldInventory' => [
                'rawFields' => count($columns),
                'concepts' => count($concepts),
                'totalPopulatedValues' => $totalPopulated,
                'productLevel' => 0,
                'variantLevel' => $variantLevel,
                'compoundFields' => 0,
                'items' => $fieldItems,
            ],
            'conceptDecisions' => [
                'counts' => $conceptCounts,
                'items' => $conceptItems,
            ],
            'proposedFieldMappings' => $proposedMappings,
            'proposedDefinitions' => $proposedDefinitions,
            'units' => $units,
            'options' => $options,
            'reviewReasons' => $reviewReasons,
            'specialInvestigations' => $specialInvestigations,
            'readiness' => [
                'CURRENT' => [
                    'ACCESSORY_DV_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
                    'CABLE_SPEC_FIELD_MAPPING_READY' => 0,
                    'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
                    'FULL_PRODUCT_MIGRATION_READY' => self::EXPECTED_CABLE_PRODUCTS,
                    'note' => 'Accessory ACCESSORY_DV / SPEC_FIELD_MAPPING_READY remain 27; cables not included until cable-spec mappings approved. FULL remains 54 (hardcoded baseline).',
                ],
                'PROJECTED' => [
                    'ACCESSORY_DV_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
                    'CABLE_SPEC_FIELD_MAPPING_READY' => $readyCount,
                    'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
                    'FULL_PRODUCT_MIGRATION_READY' => self::EXPECTED_CABLE_PRODUCTS,
                    'note' => 'Projected SPEC_FIELD_MAPPING_READY stays 27 (accessory unchanged); cables excluded until approved. FULL remains 54.',
                ],
                'products' => $readinessProducts,
            ],
            'batchC2Integrity' => $integrity,
            'dryRunTargetPlan' => [
                'writesWouldExecute' => false,
                'definitionsToCreate' => $definitionsToCreate,
                'mappingsToInsert' => 0,
                'mappingsToInsertNote' => 'dry-run only — no mapping inserts',
                'unitsToCreate' => 0,
                'optionsToCreate' => 0,
                'specificationValuesToWrite' => 0,
                'guard' => 'CABLE_SPEC_FIELD_MAPPING_APPLY_NOT_APPROVED',
            ],
            'artifacts' => [
                'cable-spec-field-inventory.json',
                'cable-spec-concept-proposals.json',
                'cable-spec-proposed-field-mappings.json',
                'cable-spec-review.json',
                'cable-spec-readiness.json',
                'cable-spec-final-dry-run-package.json',
                'cable-spec-special-investigations.json',
            ],
            'recommendation' => 'REVIEW CABLE SPEC FIELD MAPPING PACKAGE — DO NOT APPLY',
            'nextAction' => 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL',
        ];
    }

    /**
     * @param list<string> $columns
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $operator
     * @return array<string, array<string, mixed>>
     */
    private function buildFieldDecisions(array $columns, array $stats, array $operator): array
    {
        $catalog = self::fieldCatalog();
        $fields = $columns;
        foreach (array_keys($catalog) as $field) {
            if (!in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        $out = [];
        foreach ($fields as $field) {
            $meta = $catalog[$field] ?? [
                'decision' => self::REVIEW,
                'reason' => 'UNKNOWN_SEMANTICS',
                'concept' => 'UNCATALOGUED',
                'note' => 'Column present in inventory but not in fixed catalog.',
            ];

            $decision = (string) ($meta['decision'] ?? self::REVIEW);
            $reason = (string) ($meta['reason'] ?? '');

            if (!empty($meta['requiresPopulatedNonZero'])) {
                $nz = (int) ($this->fieldStat($stats, $field)['populatedNonZero'] ?? 0);
                if ($nz <= 0) {
                    $decision = self::NOT_A_SPECIFICATION;
                    $reason = 'unused_in_cable_population';
                }
            }

            if (isset($operator[$field]) && is_array($operator[$field])) {
                if (isset($operator[$field]['decision'])) {
                    $decision = MigrationPayload::string($operator[$field]['decision']);
                }
                if (isset($operator[$field]['reason'])) {
                    $reason = MigrationPayload::string($operator[$field]['reason']);
                }
                if (isset($operator[$field]['code'])) {
                    $meta['code'] = MigrationPayload::string($operator[$field]['code']);
                }
            }

            // Hard rule: od never maps to OUTER-DIAMETER
            if ($field === 'od') {
                $decision = self::PROPOSED_MAP_EXISTING;
                $meta['code'] = 'NOM-CABLE-OD';
                $meta['note'] = 'Cable OD mm → NOM-CABLE-OD. Accessory OUTER-DIAMETER is distinct and must never be aliased here.';
                $meta['neverMapTo'] = 'OUTER-DIAMETER';
                if ($reason === '' || str_contains($reason, 'unused')) {
                    $reason = 'cable_od_mm_maps_to_nom_cable_od';
                }
            }

            $out[$field] = [
                'field' => $field,
                'decision' => $decision,
                'reason' => $reason,
                'concept' => (string) ($meta['concept'] ?? 'UNKNOWN'),
                'code' => isset($meta['code']) ? (string) $meta['code'] : null,
                'name' => isset($meta['name']) ? (string) $meta['name'] : null,
                'group' => isset($meta['group']) ? (string) $meta['group'] : null,
                'dataType' => isset($meta['dataType']) ? (string) $meta['dataType'] : null,
                'unit' => $meta['unit'] ?? null,
                'note' => isset($meta['note']) ? (string) $meta['note'] : null,
                'neverMapTo' => isset($meta['neverMapTo']) ? (string) $meta['neverMapTo'] : null,
                'scope' => 'VARIANT',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $decisions
     * @param array<string, mixed> $stats
     * @return list<array<string, mixed>>
     */
    private function buildFieldInventoryItems(array $decisions, array $stats): array
    {
        $items = [];
        foreach ($decisions as $field => $d) {
            $st = $this->fieldStat($stats, $field);
            $items[] = [
                'field' => $field,
                'concept' => $d['concept'],
                'decision' => $d['decision'],
                'reason' => $d['reason'],
                'targetDefinitionCode' => $d['code'],
                'scope' => 'VARIANT',
                'populated' => (int) ($st['populated'] ?? 0),
                'populatedNonZero' => (int) ($st['populatedNonZero'] ?? 0),
                'distinct' => (int) ($st['distinct'] ?? 0),
                'styles' => (int) ($st['styles'] ?? 0),
                'samples' => $this->stringSamples($st['samples'] ?? []),
                'note' => $d['note'],
            ];
        }

        return $items;
    }

    /**
     * @param array<string, array<string, mixed>> $decisions
     * @return list<array<string, mixed>>
     */
    private function buildConceptDecisionItems(array $decisions): array
    {
        $items = [];
        foreach ($decisions as $d) {
            $items[] = [
                'field' => $d['field'],
                'concept' => $d['concept'],
                'decision' => $d['decision'],
                'reason' => $d['reason'],
                'targetDefinitionCode' => $d['code'],
                'note' => $d['note'],
            ];
        }

        return $items;
    }

    /**
     * @param array<string, array<string, mixed>> $decisions
     * @return array<string, mixed>
     */
    private function buildProposedFieldMappings(array $decisions, array $stats, int $rowCount): array
    {
        $items = [];
        foreach ($decisions as $d) {
            $decision = (string) ($d['decision'] ?? '');
            if (!in_array($decision, [self::PROPOSED_MAP_EXISTING, self::PROPOSED_CREATE], true)) {
                continue;
            }
            $items[] = [
                'sourceTable' => 'cable_specs',
                'sourceField' => $d['field'],
                'decision' => $decision,
                'targetDefinitionCode' => $d['code'],
                'targetDefinitionName' => $d['name'],
                'group' => $d['group'],
                'dataType' => $d['dataType'],
                'unit' => $d['unit'],
                'scope' => 'VARIANT',
                'reason' => $d['reason'],
                'note' => $d['note'],
                'neverMapTo' => $d['neverMapTo'],
                'valueCompatibility' => $this->buildValueCompatibility($d, $stats, $rowCount),
            ];
        }

        $items = $this->sortByField($items, 'sourceField');

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $decisions
     * @return array<string, mixed>
     */
    private function buildProposedDefinitions(array $decisions): array
    {
        $byCode = [];
        foreach ($decisions as $d) {
            if (($d['decision'] ?? '') !== self::PROPOSED_CREATE) {
                continue;
            }
            $code = (string) ($d['code'] ?? '');
            if ($code === '' || isset($byCode[$code])) {
                continue;
            }
            $byCode[$code] = [
                'code' => $code,
                'name' => $d['name'],
                'group' => $d['group'],
                'dataType' => $d['dataType'],
                'unit' => $d['unit'],
                'sourceFields' => [$d['field']],
                'reason' => $d['reason'],
                'note' => $d['note'],
            ];
        }

        $items = array_values($byCode);
        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']),
        );

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $decisions
     * @return array<string, mixed>
     */
    private function buildUnitsSection(array $decisions): array
    {
        $mapped = [];
        $missing = [];
        $ambiguous = [];

        foreach ($decisions as $d) {
            $field = (string) $d['field'];
            $decision = (string) ($d['decision'] ?? '');
            if (!in_array($decision, [self::PROPOSED_MAP_EXISTING, self::PROPOSED_CREATE, self::REVIEW], true)) {
                continue;
            }

            $unitCode = null;
            if (in_array($field, ['od', 'cdia', 'od_ins', 'ode_ins', 'od_bed', 'od_arm', 'od_brd', 'br', 'ins'], true)) {
                $unitCode = 'MM';
            } elseif ($field === 'wght') {
                $unitCode = 'KG_PER_KM';
            } elseif (in_array($field, ['A30c', 'A40c', 'A45c', 'Af'], true)) {
                $unitCode = 'A';
            } elseif (in_array($field, ['xa', 'xae'], true)) {
                $unitCode = 'MM2';
            } elseif (in_array($field, ['walu', 'wbnz', 'r', 'x50', 'x60', 'tension'], true)) {
                $ambiguous[] = [
                    'field' => $field,
                    'reason' => 'unit_ambiguous_or_unknown',
                ];
                continue;
            } elseif (in_array($field, ['nc', 'ne', 'cc', 'mt', 'ct'], true)) {
                // dimensionless / codes
                continue;
            } else {
                $missing[] = [
                    'field' => $field,
                    'reason' => 'unit_not_assigned',
                ];
                continue;
            }

            $mapped[] = [
                'field' => $field,
                'unitCode' => $unitCode,
                'createUnit' => false,
            ];
        }

        $mapped = $this->sortByField($mapped);
        $missing = $this->sortByField($missing);
        $ambiguous = $this->sortByField($ambiguous);

        return [
            'mapped' => $mapped,
            'mappedCount' => count($mapped),
            'missing' => $missing,
            'ambiguous' => $ambiguous,
            'newUnitsRequired' => 0,
            'policy' => 'Never create units in this phase; reuse existing MM / KG_PER_KM / A / MM2.',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $decisions
     * @return array<string, mixed>
     */
    private function buildOptionsSection(array $decisions): array
    {
        $optionAmbiguity = [];
        foreach (['cc', 'ct', 'mt'] as $field) {
            if (!isset($decisions[$field])) {
                continue;
            }
            $optionAmbiguity[] = [
                'field' => $field,
                'decision' => self::REVIEW,
                'classification' => 'STANDARD_OR_APPROVAL_REVIEW',
                'reason' => (string) ($decisions[$field]['reason'] ?? 'UNKNOWN_SEMANTICS'),
                'note' => (string) ($decisions[$field]['note'] ?? 'option ambiguity'),
            ];
        }

        $optionAmbiguity = $this->sortByField($optionAmbiguity);

        return [
            'newOptionsRequired' => 0,
            'note' => 'Numeric cable_specs fields need no new options in this phase.',
            'optionAmbiguity' => $optionAmbiguity,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $decisions
     * @return array<string, mixed>
     */
    private function buildReviewReasons(array $decisions): array
    {
        $counts = [];
        foreach ($decisions as $d) {
            if (($d['decision'] ?? '') !== self::REVIEW) {
                continue;
            }
            $reason = (string) ($d['reason'] ?? 'OTHER');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        ksort($counts);

        return ['counts' => $counts];
    }

    /**
     * @param list<array<string, mixed>> $cableProducts
     * @param array<string, int> $styleRowCounts
     * @param array<string, array<string, mixed>> $decisions
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function evaluateProductReadiness(
        array $cableProducts,
        array $styleRowCounts,
        array $decisions,
        array $stats,
    ): array {
        $ready = [];
        $review = [];
        $blocked = [];

        foreach ($cableProducts as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $title = MigrationPayload::string($p['productTitle'] ?? $p['title'] ?? '');
            $entry = [
                'styleCode' => $style,
                'productTitle' => $title !== '' ? $title : null,
                'family' => 'CABLE',
            ];

            $rows = $styleRowCounts[$style] ?? 0;
            if ($rows === 0) {
                $entry['status'] = 'BLOCKED';
                $entry['reason'] = 'NO_CABLE_SPECS_ROWS';
                $blocked[] = $entry;
                continue;
            }

            $needsReview = false;
            $reviewFields = [];
            foreach ($decisions as $field => $d) {
                $decision = (string) ($d['decision'] ?? '');
                if (!in_array($decision, [self::PROPOSED_CREATE, self::REVIEW, self::REJECT], true)) {
                    continue;
                }
                $populated = (int) ($this->fieldStat($stats, $field)['populated'] ?? 0);
                if ($populated <= 0) {
                    continue;
                }
                // Field is populated somewhere in inventory — for style-level readiness,
                // treat globally populated REVIEW/CREATE/REJECT fields as applying when
                // the style has cable_specs rows (variant grain shared across population).
                $stylesTouched = (int) ($this->fieldStat($stats, $field)['styles'] ?? 0);
                if ($stylesTouched > 0) {
                    // If stats report styles count and this is a global population field
                    // (styles == population), it applies to all styles with rows.
                    $needsReview = true;
                    $reviewFields[] = $field;
                }
            }

            if ($needsReview) {
                $entry['status'] = 'REVIEW';
                $entry['reason'] = 'POPULATED_FIELDS_NEED_REVIEW_OR_CREATE';
                $entry['reviewFields'] = array_values(array_unique($reviewFields));
                $review[] = $entry;
            } else {
                $entry['status'] = 'READY';
                $entry['reason'] = 'ALL_POPULATED_FIELDS_MAP_EXISTING_OR_NOT_A_SPEC';
                $ready[] = $entry;
            }
        }

        $byStyle = static fn (array $a, array $b): int => strcmp((string) ($a['styleCode'] ?? ''), (string) ($b['styleCode'] ?? ''));
        usort($ready, $byStyle);
        usort($review, $byStyle);
        usort($blocked, $byStyle);

        return [
            'READY' => count($ready),
            'REVIEW' => count($review),
            'BLOCKED' => count($blocked),
            'items' => [
                'READY' => array_slice($ready, 0, 20),
                'REVIEW' => array_slice($review, 0, 20),
                'BLOCKED' => array_slice($blocked, 0, 20),
                'summaryNote' => 'Item lists capped at 20 per status for package size.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function buildBatchC2Integrity(array $azure): array
    {
        $definitions = (int) ($azure['definitionCount'] ?? count(is_array($azure['definitions'] ?? null) ? $azure['definitions'] : []));
        $styleMappings = (int) ($azure['styleFieldMappingCount'] ?? 0);
        $totalMappings = (int) ($azure['fieldMappingCount'] ?? 0);

        $drift = [];
        if ($definitions > 0 && $definitions !== self::EXPECTED_DEFINITIONS) {
            $drift[] = 'definitionCount expected ' . self::EXPECTED_DEFINITIONS . ' got ' . $definitions;
        }
        if ($styleMappings > 0 && $styleMappings !== self::EXPECTED_STYLE_FIELD_MAPPINGS) {
            $drift[] = 'styleFieldMappingCount expected ' . self::EXPECTED_STYLE_FIELD_MAPPINGS . ' got ' . $styleMappings;
        }
        if ($totalMappings > 0 && $totalMappings !== self::EXPECTED_FIELD_MAPPINGS) {
            $drift[] = 'fieldMappingCount expected ' . self::EXPECTED_FIELD_MAPPINGS . ' got ' . $totalMappings;
        }

        return [
            'definitions' => $definitions ?: self::EXPECTED_DEFINITIONS,
            'styleMappings' => $styleMappings ?: self::EXPECTED_STYLE_FIELD_MAPPINGS,
            'totalMappings' => $totalMappings ?: self::EXPECTED_FIELD_MAPPINGS,
            'expected' => [
                'definitions' => self::EXPECTED_DEFINITIONS,
                'styleMappings' => self::EXPECTED_STYLE_FIELD_MAPPINGS,
                'totalMappings' => self::EXPECTED_FIELD_MAPPINGS,
            ],
            'drift' => $drift,
            'driftDetected' => $drift !== [],
            'mappingsModified' => 0,
            'definitionsModified' => 0,
            'outerDiameterPreserved' => true,
            'nomCableOdDistinct' => true,
            'note' => $drift === []
                ? 'Batch C2 integrity flags preserved (55 / 483 / 506).'
                : 'Batch C2 integrity drift reported; package still produced.',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $decisions
     */
    private function assertNeverProposesIndLszhFire(array $decisions): void
    {
        foreach ($decisions as $d) {
            $code = strtoupper((string) ($d['code'] ?? ''));
            if ($code === 'IND-LSZH-FIRE') {
                throw new \RuntimeException('Blocked: IND-LSZH-FIRE must not be proposed in cable-spec field mapping');
            }
        }
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function normalizeProductList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (isset($value['items']) && is_array($value['items'])) {
            $value = $value['items'];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function filterCableProducts(array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            if (strtoupper(MigrationPayload::string($p['family'] ?? '')) === 'CABLE') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param mixed $rows
     * @return array<string, int>
     */
    private function indexStyleRowCounts(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $style = strtoupper(MigrationPayload::string($row['cable_style'] ?? $row['style'] ?? $row['styleCode'] ?? ''));
            if ($style === '') {
                continue;
            }
            $out[$style] = (int) ($row['c'] ?? $row['rowCount'] ?? $row['count'] ?? 0);
        }

        return $out;
    }

    /**
     * @param mixed $columns
     * @return list<string>
     */
    private function normalizeColumns(mixed $columns): array
    {
        if (!is_array($columns)) {
            return array_keys(self::fieldCatalog());
        }
        $out = [];
        foreach ($columns as $col) {
            if (is_string($col) && $col !== '') {
                $out[] = $col;
            }
        }

        if ($out === []) {
            $out = array_keys(self::fieldCatalog());
        }
        sort($out);

        return $out;
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function fieldStat(array $stats, string $field): array
    {
        $st = $stats[$field] ?? null;

        return is_array($st) ? $st : [];
    }

    /**
     * @param mixed $samples
     * @return list<string>
     */
    private function stringSamples(mixed $samples): array
    {
        if (!is_array($samples)) {
            return [];
        }
        $out = [];
        foreach ($samples as $s) {
            if (is_string($s) || is_int($s) || is_float($s)) {
                $out[] = (string) $s;
            }
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $fieldDistributions
     * @return array<string, mixed>
     */
    private function buildSpecialInvestigations(array $stats, array $fieldDistributions, int $cableProductCount): array
    {
        $specs = [
            'cc' => [
                'inferredMeaning' => 'legacy construction/class code (unconfirmed); possible copper/class/construction flag',
                'inferredType' => 'code',
                'inferredUnit' => null,
                'likelyTarget' => null,
                'defaultSamples' => ['c', 'p', 't', 'a'],
            ],
            'mt' => [
                'inferredMeaning' => 'legacy material/type lookup code (unconfirmed)',
                'inferredType' => 'code',
                'inferredUnit' => null,
                'likelyTarget' => null,
                'defaultSamples' => ['1301', '1302', '0'],
            ],
            'ct' => [
                'inferredMeaning' => 'legacy conductor/construction type flag (unconfirmed); p dominates, a minority',
                'inferredType' => 'code',
                'inferredUnit' => null,
                'likelyTarget' => null,
                'defaultSamples' => ['p', 'a'],
            ],
        ];

        $out = [];
        foreach ($specs as $field => $meta) {
            $st = $this->fieldStat($stats, $field);
            $dist = is_array($fieldDistributions[$field] ?? null) ? $fieldDistributions[$field] : [];
            $samples = $this->stringSamples($st['samples'] ?? []);
            if ($samples === []) {
                $samples = $meta['defaultSamples'];
            } else {
                foreach ($meta['defaultSamples'] as $must) {
                    if (!in_array($must, $samples, true)) {
                        $samples[] = $must;
                    }
                }
            }

            // Prefer documented live-evidence order for known fields.
            $ordered = [];
            foreach ($meta['defaultSamples'] as $v) {
                if (in_array($v, $samples, true)) {
                    $ordered[] = $v;
                }
            }
            foreach ($samples as $v) {
                if (!in_array($v, $ordered, true)) {
                    $ordered[] = $v;
                }
            }
            $samples = $ordered;

            $styles = (int) ($st['styles'] ?? 0);
            $nonEmpty = (int) ($st['populated'] ?? 0);
            $distinct = (int) ($st['distinct'] ?? 0);
            if ($dist !== []) {
                $distinct = max($distinct, count($dist));
            }

            $products = 0;
            if ($styles > 0) {
                $products = $styles;
            } elseif ($nonEmpty > 0) {
                $products = $cableProductCount;
            }

            $out[$field] = [
                'field' => $field,
                'products' => $products,
                'styles' => $styles,
                'nonEmpty' => $nonEmpty,
                'distinct' => $distinct,
                'samples' => $samples,
                'valueDistribution' => $this->normalizeDistribution($dist),
                'inferredMeaning' => $meta['inferredMeaning'],
                'inferredType' => $meta['inferredType'],
                'inferredUnit' => $meta['inferredUnit'],
                'likelyTarget' => $meta['likelyTarget'],
                'confidence' => 'low',
                'decision' => self::REVIEW,
                'reason' => 'UNKNOWN_SEMANTICS',
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $dist
     * @return list<array<string, mixed>>
     */
    private function normalizeDistribution(array $dist): array
    {
        $items = [];
        foreach ($dist as $row) {
            $items[] = [
                'value' => (string) ($row['value'] ?? ''),
                'count' => (int) ($row['count'] ?? $row['cnt'] ?? 0),
                'styles' => (int) ($row['styles'] ?? 0),
            ];
        }
        usort(
            $items,
            static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp((string) $a['value'], (string) $b['value']),
        );

        return $items;
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function buildValueCompatibility(array $decision, array $stats, int $rowCount): array
    {
        $field = (string) ($decision['field'] ?? '');
        $st = $this->fieldStat($stats, $field);
        $populated = (int) ($st['populated'] ?? 0);
        $emptyCount = max(0, $rowCount - $populated);
        $samples = $this->stringSamples($st['samples'] ?? []);
        $dataType = strtolower((string) ($decision['dataType'] ?? ''));

        $parseable = $populated;
        $failures = 0;

        if (in_array($dataType, ['decimal', 'integer'], true) && $samples !== []) {
            $ok = 0;
            $bad = 0;
            foreach ($samples as $sample) {
                if ($this->isNumericSample($sample, $dataType)) {
                    ++$ok;
                } else {
                    ++$bad;
                }
            }
            $totalSamples = $ok + $bad;
            if ($totalSamples > 0 && $bad > 0) {
                $parseable = (int) round($populated * ($ok / $totalSamples));
                $failures = max(0, $populated - $parseable);
            }
        }

        // Numeric proposals are not option-backed; leave null/NA.
        $optionCompatible = null;
        if (!in_array($dataType, ['decimal', 'integer', 'text'], true)) {
            $optionCompatible = 0;
        }
        if (in_array($field, ['cc', 'mt', 'ct'], true)) {
            $optionCompatible = 0;
        }

        return [
            'totalValues' => $populated,
            'parseableValues' => $parseable,
            'parseFailureCount' => $failures,
            'emptyCount' => $emptyCount,
            'typeCompatibleCount' => $parseable,
            'unitCompatibleCount' => $parseable,
            'optionCompatibleCount' => $optionCompatible,
        ];
    }

    private function isNumericSample(string $sample, string $dataType): bool
    {
        $trimmed = trim($sample);
        if ($trimmed === '') {
            return false;
        }
        if ($dataType === 'integer') {
            return preg_match('/^-?\d+$/', $trimmed) === 1;
        }

        return is_numeric($trimmed);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function sortByField(array $items, string $key = 'field'): array
    {
        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? '')),
        );

        return $items;
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
}
