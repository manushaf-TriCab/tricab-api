<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.7 Batch D.5 — Cable Spec REVIEW investigation (read-only).
 *
 * Validates D.4 integrity, deep-reads legacy evidence for cc/ct/walu/wbnz/ins/B1 fields,
 * proposes target models without writing Definitions/mappings/values.
 */
final class InvestigateCableSpecBatchD5ReviewService
{
    public const PROPOSED_MAP_EXISTING = 'PROPOSED_MAP_EXISTING';
    public const PROPOSED_CREATE_DEFINITION = 'PROPOSED_CREATE_DEFINITION';
    public const PROPOSED_CREATE_UNIT = 'PROPOSED_CREATE_UNIT';
    public const PROPOSED_CREATE_OPTIONS = 'PROPOSED_CREATE_OPTIONS';
    public const NOT_A_SPECIFICATION_CANDIDATE = 'NOT_A_SPECIFICATION_CANDIDATE';
    public const REVIEW = 'REVIEW';
    public const REJECT_CANDIDATE = 'REJECT_CANDIDATE';

    public const D5_BLOCKED_D4_INTEGRITY = 'D5_BLOCKED_D4_INTEGRITY';

    private const EXPECTED_CABLE_PRODUCTS = 54;
    private const ACCESSORY_SPEC_FIELD_MAPPING_READY = 27;

    /** @var list<string> */
    public const REVIEW_FIELDS = ['cc', 'ct', 'walu', 'wbnz', 'ins', 'Af', 'r', 'x50', 'x60', 'tension'];

    /** @var list<string> */
    private const ALWAYS_POPULATED_FIELDS = ['cc', 'ct'];

    /** @var array<string, list<string>> */
    private const DEFAULT_STYLE_PRESENCE = [
        'Af' => ['B1'],
        'r' => ['B1'],
        'x50' => ['B1'],
        'x60' => ['B1'],
        'tension' => ['B1'],
        'ins' => ['B1', 'KN', 'NR', 'NV', 'XN'],
        'walu' => ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'],
        'wbnz' => ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'],
    ];

    /** @var list<string> */
    private const CONDUCTOR_DEFINITION_SEARCH = [
        'CONDUCTOR-MATERIAL',
        'CONDUCTOR-TYPE',
        'CODE_CONDUCTOR',
        'CONDUCTORS',
        'MATERIAL',
        'COPPER',
        'ALUMINIUM',
        'ALUMINUM',
    ];

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function investigate(array $inputs): array
    {
        $forbidden = (int) ($inputs['forbiddenWriteAttempts'] ?? 0);
        $azure = is_array($inputs['azure'] ?? null) ? $inputs['azure'] : [];
        $evidence = is_array($inputs['investigationEvidence'] ?? null)
            ? $inputs['investigationEvidence']
            : CableSpecBatchD5InvestigationCollector::embeddedEvidence();
        $products = $this->filterCableProducts($inputs['classificationReadyProducts'] ?? []);
        $cableStyles = $this->cableStyleCodes($products);

        $d4Integrity = $this->validateD4Integrity($azure);
        if (($d4Integrity['status'] ?? '') === self::D5_BLOCKED_D4_INTEGRITY) {
            return $this->blockedPackage($d4Integrity, $forbidden);
        }

        $presence = $this->mergePresence($evidence, $cableStyles);
        $targetScan = $this->scanTargetMaster($azure);
        $codebaseEvidence = $this->buildCodebaseEvidence();
        $proposals = $this->buildProposals($evidence, $targetScan, $codebaseEvidence, $presence);
        $blockerMatrix = $this->buildBlockerMatrix($products, $presence, $proposals);
        $readinessScenarios = $this->buildReadinessScenarios($products, $presence, $proposals);
        $masterRequirements = $this->buildMasterDataRequirements($proposals, $targetScan);

        $artifacts = [
            'cable-spec-d5-field-evidence.json',
            'cable-spec-d5-cc-investigation.json',
            'cable-spec-d5-ct-investigation.json',
            'cable-spec-d5-weight-investigation.json',
            'cable-spec-d5-ins-investigation.json',
            'cable-spec-d5-b1-investigation.json',
            'cable-spec-d5-proposals.json',
            'cable-spec-d5-readiness-scenarios.json',
            'cable-spec-d5-final-review-package.json',
        ];

        $highCount = count(array_filter($proposals, static fn (array $p): bool => ($p['confidence'] ?? '') === 'HIGH'));
        $complete = count($proposals) === count(self::REVIEW_FIELDS) && $highCount > 0;

        return [
            'phase' => '12.7-batch-d5-cable-spec-review-investigation',
            'mode' => 'READ_INVESTIGATE_PROPOSE_DRY_RUN_ONLY',
            'packageStatus' => $complete ? 'READY_FOR_OPERATOR_DECISIONS' : 'ADDITIONAL_EVIDENCE_REQUIRED',
            'investigationBanner' => 'D.5 INVESTIGATION ONLY — NOTHING APPLIED',
            'cableSpecApply' => 'NOT_AVAILABLE',
            'approvalBoundary' => [
                'cableSpecD5ApplyGuard' => CableSpecBatchD5CliGuard::APPLY_NOT_AVAILABLE,
                'operatorDecisionsRequired' => true,
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => $forbidden,
            ],
            'targetSafety' => [
                'definitionWrites' => 0,
                'optionWrites' => 0,
                'unitWrites' => 0,
                'fieldMappingWrites' => 0,
                'productSpecValueWrites' => 0,
                'variantSpecValueWrites' => 0,
                'totalWrites' => 0,
            ],
            'd4Integrity' => $d4Integrity,
            'targetMasterScan' => $targetScan,
            'codebaseEvidence' => $codebaseEvidence,
            'investigationEvidenceMode' => (string) ($evidence['mode'] ?? 'UNKNOWN'),
            'proposals' => [
                'count' => count($proposals),
                'items' => $proposals,
            ],
            'blockerMatrix' => $blockerMatrix,
            'readinessScenarios' => $readinessScenarios,
            'masterDataRequirements' => $masterRequirements,
            'remainingBlockers' => $blockerMatrix['conceptSummary'] ?? [],
            'nextGate' => $this->buildNextGate($blockerMatrix, $proposals),
            'semanticGuards' => [
                'noRawCcCtOptions' => true,
                'noSpeculativeDefinitionCreation' => true,
                'noIndLszhFire' => true,
                'odNomCableOdGuardPreserved' => true,
                'd4MappingsUnchanged' => true,
                'highConfidenceRequiresDirectEvidence' => true,
            ],
            'artifacts' => $artifacts,
            'generatedAtUtc' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function validateD4Integrity(array $azure): array
    {
        $definitions = (int) ($azure['definitionCount'] ?? count($azure['definitions'] ?? []));
        $totalMappings = (int) ($azure['fieldMappingCount'] ?? 0);
        $cableMappings = (int) ($azure['cableFieldMappingCount'] ?? 0);
        $styleMappings = (int) ($azure['styleFieldMappingCount'] ?? 0);

        $expectedDefs = ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST;
        $expectedTotal = ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST;
        $expectedCable = ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT;

        $missingCodes = [];
        $defsByCode = $this->indexDefinitionCodes($azure['definitions'] ?? []);
        foreach (ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES as $code) {
            if (!isset($defsByCode[strtoupper($code)])) {
                $missingCodes[] = $code;
            }
        }

        $drift =
            $definitions !== $expectedDefs
            || $totalMappings !== $expectedTotal
            || $cableMappings !== $expectedCable
            || $missingCodes !== [];

        $status = $drift ? self::D5_BLOCKED_D4_INTEGRITY : 'D4_INTEGRITY_OK';

        return [
            'status' => $status,
            'definitions' => $definitions,
            'expectedDefinitions' => $expectedDefs,
            'totalFieldMappings' => $totalMappings,
            'expectedTotalFieldMappings' => $expectedTotal,
            'cableFieldMappings' => $cableMappings,
            'expectedCableFieldMappings' => $expectedCable,
            'styleFieldMappings' => $styleMappings,
            'd4DefinitionCodesPresent' => array_values(array_diff(
                ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES,
                $missingCodes,
            )),
            'd4DefinitionCodesMissing' => $missingCodes,
            'driftDetected' => $drift,
            'drift' => [
                'definitions' => $definitions - $expectedDefs,
                'totalFieldMappings' => $totalMappings - $expectedTotal,
                'cableFieldMappings' => $cableMappings - $expectedCable,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $d4Integrity
     * @return array<string, mixed>
     */
    private function blockedPackage(array $d4Integrity, int $forbidden): array
    {
        return [
            'phase' => '12.7-batch-d5-cable-spec-review-investigation',
            'mode' => 'BLOCKED_D4_INTEGRITY',
            'packageStatus' => self::D5_BLOCKED_D4_INTEGRITY,
            'investigationBanner' => 'D.5 INVESTIGATION ONLY — NOTHING APPLIED',
            'legacySafety' => ['wordpressWrites' => 0, 'tcWrites' => 0, 'forbiddenWriteAttempts' => $forbidden],
            'targetSafety' => [
                'definitionWrites' => 0,
                'optionWrites' => 0,
                'unitWrites' => 0,
                'fieldMappingWrites' => 0,
                'productSpecValueWrites' => 0,
                'variantSpecValueWrites' => 0,
                'totalWrites' => 0,
            ],
            'd4Integrity' => $d4Integrity,
            'proposals' => ['count' => 0, 'items' => []],
            'blockerMatrix' => [],
            'readinessScenarios' => [],
            'generatedAtUtc' => gmdate('c'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @return array<string, array<string, mixed>>
     */
    private function indexDefinitionCodes(array $definitions): array
    {
        $out = [];
        foreach ($definitions as $def) {
            if (!is_array($def)) {
                continue;
            }
            $code = strtoupper(trim((string) ($def['code'] ?? $def['DefinitionCode'] ?? '')));
            if ($code !== '') {
                $out[$code] = $def;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $cableStyles
     * @return array<string, list<string>>
     */
    private function mergePresence(array $evidence, array $cableStyles): array
    {
        $presence = self::DEFAULT_STYLE_PRESENCE;
        $d2Echo = is_array($evidence['d2Echo'] ?? null) ? $evidence['d2Echo'] : [];
        $fromD2 = is_array($d2Echo['styleFieldPresence'] ?? null) ? $d2Echo['styleFieldPresence'] : [];
        foreach ($fromD2 as $field => $styles) {
            if (!is_string($field) || !is_array($styles)) {
                continue;
            }
            $presence[$field] = array_values(array_unique(array_map(
                static fn ($s): string => strtoupper(trim((string) $s)),
                $styles,
            )));
        }
        foreach (self::ALWAYS_POPULATED_FIELDS as $field) {
            $presence[$field] = $cableStyles;
        }

        return $presence;
    }

    /**
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function scanTargetMaster(array $azure): array
    {
        $defsByCode = $this->indexDefinitionCodes($azure['definitions'] ?? []);
        $units = [];
        foreach ($azure['units'] ?? [] as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $code = strtoupper(trim((string) ($unit['code'] ?? $unit['UnitCode'] ?? '')));
            if ($code !== '') {
                $units[$code] = $unit;
            }
        }

        $conductorMatches = [];
        foreach (self::CONDUCTOR_DEFINITION_SEARCH as $needle) {
            $upper = strtoupper($needle);
            if (isset($defsByCode[$upper])) {
                $conductorMatches[$upper] = $defsByCode[$upper];
            }
        }

        $hasKgPerKm = isset($units['KG_PER_KM']) || isset($units['KG/KM']);
        $imperialMassLength = [];
        foreach (['LB_PER_1000FT', 'LB/1000FT', 'LB_PER_1000_FT'] as $code) {
            if (isset($units[$code])) {
                $imperialMassLength[$code] = $units[$code];
            }
        }

        return [
            'definitionCount' => count($defsByCode),
            'unitCount' => count($units),
            'conductorDefinitionMatches' => $conductorMatches,
            'conductorsLongTextNote' => isset($defsByCode['CONDUCTORS'])
                ? 'CONDUCTORS exists as long-text construction descriptor — not ct p/a encoding'
                : null,
            'codeConductorNote' => isset($defsByCode['CODE_CONDUCTOR'])
                ? 'CODE_CONDUCTOR exists — option-based conductor code, not cable_specs.ct'
                : null,
            'units' => [
                'KG_PER_KM' => $hasKgPerKm,
                'imperialMassPerLength' => $imperialMassLength,
                'existingUnitCodes' => array_keys($units),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCodebaseEvidence(): array
    {
        return [
            'confidenceRule' => 'HIGH requires direct legacy label/documentation/calculation — not variable-name inference',
            'ccCtLegacySourceScan' => [
                'tricabApiMigrationCode' => 'No explicit cable_specs.cc / cable_specs.ct UI labels in migration codebase',
                'operatorReconsiderationAvailable' => false,
                'ratingCtEncoding' => 'rating.ct uses C/A alphabet — differs from cable_specs.ct p/a',
                'cable3dMapping' => 'Conductor material resolved via CODE_CONDUCTOR / CONDUCTORS — not ct field',
            ],
            'waluWbnzLabels' => [
                'directUnitTextFound' => false,
                'note' => 'No lb/1000ft or kg/km label strings tied to walu/wbnz in scanned migration services',
            ],
            'insLabels' => [
                'directThicknessLabelFound' => false,
                'dimensionalFormula' => 'ins ≈ (od_ins - cdia)/2 tested in D.5 collector for NR/NV rows only',
            ],
            'b1Engineering' => [
                'fields' => ['Af', 'r', 'x50', 'x60', 'tension'],
                'style' => 'B1',
                'directLabelsFound' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $targetScan
     * @param array<string, mixed> $codebaseEvidence
     * @param array<string, list<string>> $presence
     * @return list<array<string, mixed>>
     */
    private function buildProposals(
        array $evidence,
        array $targetScan,
        array $codebaseEvidence,
        array $presence,
    ): array {
        $d2Echo = is_array($evidence['d2Echo'] ?? null) ? $evidence['d2Echo'] : [];
        $samples = is_array($d2Echo['sampleInvestigations'] ?? null) ? $d2Echo['sampleInvestigations'] : [];
        $ccByValue = is_array($evidence['ccByValue'] ?? null) ? $evidence['ccByValue'] : [];
        $ctByValue = is_array($evidence['ctByValue'] ?? null) ? $evidence['ctByValue'] : [];
        $waluAnalysis = is_array($evidence['waluWbnzUnitAnalysis'] ?? null) ? $evidence['waluWbnzUnitAnalysis'] : [];
        $insDim = is_array($evidence['insDimensionalCheck'] ?? null) ? $evidence['insDimensionalCheck'] : [];
        $b1 = is_array($evidence['b1FieldSamples'] ?? null) ? $evidence['b1FieldSamples'] : [];

        $proposals = [];

        $proposals[] = $this->proposalRow(
            'cc',
            'Core counting / configuration mode (c=cores, p=pairs, t=triples, a=unknown — AWG hypothesis LOW)',
            self::REVIEW,
            null,
            null,
            null,
            'MEDIUM',
            [
                'Values c/p/t/a across 4491 non-empty rows on 54 styles.',
                (string) ($samples['cc_patterns'] ?? 'c dominates; p pairs; t triples; a only VUF (8 rows)'),
                'No direct legacy UI label or spec_labels entry found (codebase scan).',
                'cc×nc correlation: c aligns with nc>1 cores; p with pairs; t with triples — supporting only.',
                'cc=a on style VUF only — AWG hypothesis not proven.',
            ],
            $presence['cc'] ?? [],
            $this->rowsForField($ccByValue),
        );

        $ctTarget = isset($targetScan['conductorDefinitionMatches']['CONDUCTORS'])
            ? 'CONDUCTORS (long-text — semantic mismatch risk)'
            : null;
        $proposals[] = $this->proposalRow(
            'ct',
            'Conductor material/type encoding (p vs a — copper vs aluminium hypothesis MEDIUM)',
            self::REVIEW,
            $ctTarget,
            null,
            null,
            'MEDIUM',
            [
                'Values p (4215 rows) and a (276 rows) on all 54 styles.',
                (string) ($samples['ct_kl'] ?? 'KL ct=a lighter wght + larger OD vs ct=p'),
                'rating.ct uses C/A — different alphabet from cable_specs.ct p/a.',
                'Target CONDUCTORS/CODE_CONDUCTOR exist but are not proven equivalents.',
                'No direct legacy label for cable_specs.ct found.',
            ],
            $presence['ct'] ?? [],
            $this->rowsForField($ctByValue),
        );

        $waluUnit = ($targetScan['units']['imperialMassPerLength'] ?? []) === []
            ? 'LB_PER_1000FT (candidate — not in target Units)'
            : null;
        $proposals[] = $this->proposalRow(
            'walu',
            'Approximate aluminium component weight (imperial rows only)',
            self::PROPOSED_CREATE_DEFINITION,
            'APPROX-WEIGHT-ALU',
            $waluUnit,
            null,
            'HIGH',
            [
                'Distinct from wght; non-zero only when mt=1302 (Imperial System).',
                'Parallel numeric column alongside APPROX-WEIGHT (wght) on imperial styles.',
                '14 styles populated — D.2 semantic evidence retained.',
                'Unit: imperial mass/length likely lb/1000ft — MEDIUM confidence (no direct label).',
            ],
            $presence['walu'] ?? [],
            null,
            self::PROPOSED_CREATE_UNIT,
        );

        $proposals[] = $this->proposalRow(
            'wbnz',
            'Approximate bronze component weight (imperial rows only)',
            self::PROPOSED_CREATE_DEFINITION,
            'APPROX-WEIGHT-BRONZE',
            $waluUnit,
            null,
            'HIGH',
            [
                'Distinct from wght; non-zero only when mt=1302.',
                '14 styles — mirrors walu population pattern.',
                'Unit: imperial mass/length likely lb/1000ft — MEDIUM confidence.',
            ],
            $presence['wbnz'] ?? [],
            null,
            self::PROPOSED_CREATE_UNIT,
        );

        $insThicknessMatches = (int) ($insDim['matchesThicknessFormula'] ?? 0);
        $proposals[] = $this->proposalRow(
            'ins',
            'Insulation dimension — thickness vs OD ambiguity across styles',
            self::REVIEW,
            null,
            'MM (if thickness) or MM (if OD-like)',
            null,
            'MEDIUM',
            [
                (string) ($samples['ins_ambiguity'] ?? 'NR/NV thickness-like; KN/XN OD-like'),
                "Dimensional check: {$insThicknessMatches}/" . (int) ($insDim['totalWithCdiaOdIns'] ?? 0) . ' rows match ins≈(od_ins-cdia)/2',
                'TYPE_AMBIGUITY — cannot approve single Definition without style-specific rules.',
            ],
            $presence['ins'] ?? [],
        );

        $proposals[] = $this->proposalRow(
            'Af',
            'Unknown engineering scalar — B1 only (not proven current)',
            self::REVIEW,
            null,
            null,
            null,
            'LOW',
            [
                'Populated only on B1 (11 rows).',
                'Scales with cable size — earth-fault/current hypothesis not proven.',
                (string) ($codebaseEvidence['b1Engineering']['directLabelsFound'] ?? false),
            ],
            $presence['Af'] ?? [],
        );

        $proposals[] = $this->proposalRow(
            'r',
            'Unknown — resistance hypothesis not proven (unit unclear)',
            self::REVIEW,
            null,
            'Ω/km or Ω/1000ft (unproven)',
            null,
            'LOW',
            [
                'B1 only; no explicit Ω/km legacy label found.',
                'Correlate with xa/mt/x50/x60 inconclusive for HIGH confidence.',
            ],
            $presence['r'] ?? [],
        );

        $proposals[] = $this->proposalRow(
            'x50',
            'Reactance at 50 Hz hypothesis — not proven',
            self::REVIEW,
            'REACTANCE-50HZ (candidate only)',
            null,
            null,
            'LOW',
            [
                'B1 only; no direct 50Hz/reactance label in codebase scan.',
                'Do not create Definition in D.5.',
            ],
            $presence['x50'] ?? [],
        );

        $proposals[] = $this->proposalRow(
            'x60',
            'Reactance at 60 Hz hypothesis — not proven',
            self::REVIEW,
            'REACTANCE-60HZ (candidate only)',
            null,
            null,
            'LOW',
            [
                'B1 only; no direct 60Hz/reactance label in codebase scan.',
            ],
            $presence['x60'] ?? [],
        );

        $proposals[] = $this->proposalRow(
            'tension',
            'Mechanical/pulling tension hypothesis — unit and meaning unproven',
            self::REVIEW,
            null,
            'N or kN or lbf (unproven)',
            null,
            'LOW',
            [
                'B1 only; no installation tension label found in migration codebase scan.',
            ],
            $presence['tension'] ?? [],
        );

        return $proposals;
    }

    /**
     * @param list<string> $styles
     */
    private function proposalRow(
        string $field,
        string $meaning,
        string $decision,
        ?string $targetDefinition,
        ?string $targetUnit,
        ?string $targetOptions,
        string $confidence,
        array $evidence,
        array $styles,
        ?int $rows = null,
        ?string $unitDecision = null,
    ): array {
        $productsBlocked = count($styles);
        if (in_array($field, self::ALWAYS_POPULATED_FIELDS, true)) {
            $productsBlocked = self::EXPECTED_CABLE_PRODUCTS;
        }

        $row = [
            'legacyField' => $field,
            'inferredMeaning' => $meaning,
            'decision' => $decision,
            'targetDefinition' => $targetDefinition,
            'targetUnit' => $targetUnit,
            'targetOptions' => $targetOptions,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'stylesAffected' => count($styles),
            'styleSamples' => array_slice($styles, 0, 14),
            'rowsAffected' => $rows,
            'productsBlocked' => $productsBlocked,
        ];
        if ($unitDecision !== null) {
            $row['unitDecision'] = $unitDecision;
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $byValue
     */
    private function rowsForField(array $byValue): ?int
    {
        $sum = 0;
        foreach ($byValue as $row) {
            $sum += (int) ($row['rows'] ?? 0);
        }

        return $sum > 0 ? $sum : null;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, list<string>> $presence
     * @param list<array<string, mixed>> $proposals
     * @return array<string, mixed>
     */
    private function buildBlockerMatrix(array $products, array $presence, array $proposals): array
    {
        $byField = [];
        foreach ($proposals as $p) {
            $byField[(string) ($p['legacyField'] ?? '')] = $p;
        }

        $conceptSummary = [];
        foreach (self::REVIEW_FIELDS as $field) {
            $conceptSummary[] = [
                'concept' => $field,
                'productsBlocked' => (int) ($byField[$field]['productsBlocked'] ?? 0),
                'confidence' => (string) ($byField[$field]['confidence'] ?? 'LOW'),
                'decision' => (string) ($byField[$field]['decision'] ?? self::REVIEW),
            ];
            $conceptSummary[count($conceptSummary) - 1]['productsBlocked'] = $this->fieldBlocksProducts($field, $presence);
        }

        $perProduct = [];
        foreach ($products as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $blockers = [];
            foreach (self::REVIEW_FIELDS as $field) {
                if ($this->fieldBlocksStyle($field, $style, $presence)) {
                    $blockers[] = $field;
                }
            }
            $perProduct[] = [
                'styleCode' => $style,
                'blockers' => $blockers,
                'status' => $blockers === [] ? 'READY' : 'REVIEW',
            ];
        }

        $critical = [];
        foreach (self::REVIEW_FIELDS as $field) {
            $critical[$field] = $this->fieldBlocksProducts($field, $presence);
        }

        return [
            'products' => $perProduct,
            'conceptSummary' => $conceptSummary,
            'criticalCodeBlockers' => $critical,
        ];
    }

    /**
     * @param array<string, list<string>> $presence
     */
    private function fieldBlocksStyle(string $field, string $style, array $presence): bool
    {
        if (in_array($field, self::ALWAYS_POPULATED_FIELDS, true)) {
            return true;
        }
        $styles = $presence[$field] ?? [];

        return in_array($style, $styles, true);
    }

    /**
     * @param array<string, list<string>> $presence
     */
    private function fieldBlocksProducts(string $field, array $presence): int
    {
        if (in_array($field, self::ALWAYS_POPULATED_FIELDS, true)) {
            return self::EXPECTED_CABLE_PRODUCTS;
        }

        return count($presence[$field] ?? []);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, list<string>> $presence
     * @param list<array<string, mixed>> $proposals
     * @return array<string, mixed>
     */
    private function buildReadinessScenarios(
        array $products,
        array $presence,
        array $proposals,
    ): array {
        $highFields = [];
        foreach ($proposals as $p) {
            if (($p['confidence'] ?? '') === 'HIGH' && ($p['decision'] ?? '') === self::PROPOSED_CREATE_DEFINITION) {
                $highFields[] = (string) ($p['legacyField'] ?? '');
            }
        }

        $allCandidateFields = [];
        foreach ($proposals as $p) {
            $decision = (string) ($p['decision'] ?? self::REVIEW);
            if ($decision !== self::REVIEW && $decision !== self::REJECT_CANDIDATE) {
                $allCandidateFields[] = (string) ($p['legacyField'] ?? '');
            }
        }

        return [
            'current' => $this->computeReadiness($products, $presence, []),
            'highConfidenceOnly' => $this->computeReadiness($products, $presence, $highFields),
            'ccOnly' => $this->computeReadiness($products, $presence, ['cc']),
            'ctOnly' => $this->computeReadiness($products, $presence, ['ct']),
            'ccAndCt' => $this->computeReadiness($products, $presence, ['cc', 'ct']),
            'allHighConfidenceBlockersResolved' => $this->computeReadiness($products, $presence, $highFields),
            'allProposedCandidatesResolved' => $this->computeReadiness($products, $presence, $allCandidateFields),
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, list<string>> $presence
     * @param list<string> $resolvedFields
     * @return array<string, mixed>
     */
    private function computeReadiness(array $products, array $presence, array $resolvedFields): array
    {
        $resolved = array_map('strval', $resolvedFields);
        $ready = 0;
        $review = 0;
        $stillBlocking = [];

        foreach ($products as $p) {
            $style = strtoupper((string) ($p['style_code'] ?? $p['styleCode'] ?? ''));
            $blockers = [];
            foreach (self::REVIEW_FIELDS as $field) {
                if (in_array($field, $resolved, true)) {
                    continue;
                }
                if ($this->fieldBlocksStyle($field, $style, $presence)) {
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
            'SPEC_FIELD_MAPPING_READY' => self::ACCESSORY_SPEC_FIELD_MAPPING_READY,
            'FULL_PRODUCT_MIGRATION_READY' => self::EXPECTED_CABLE_PRODUCTS,
            'stillBlockingConcepts' => $stillBlocking,
            'note' => 'Projection only — D.5 does not mutate target readiness.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $proposals
     * @param array<string, mixed> $targetScan
     * @return array<string, mixed>
     */
    private function buildMasterDataRequirements(array $proposals, array $targetScan): array
    {
        $mapExisting = [];
        $createDefs = [];
        $reuseUnits = [];
        $createUnits = [];
        $createOptions = [];

        foreach ($proposals as $p) {
            $field = (string) ($p['legacyField'] ?? '');
            $decision = (string) ($p['decision'] ?? '');
            $code = $p['targetDefinition'] ?? null;
            $unit = $p['targetUnit'] ?? null;

            if ($decision === self::PROPOSED_MAP_EXISTING && is_string($code)) {
                $mapExisting[$field] = $code;
            }
            if ($decision === self::PROPOSED_CREATE_DEFINITION && is_string($code)) {
                $createDefs[$field] = $code;
            }
            if (($p['unitDecision'] ?? '') === self::PROPOSED_CREATE_UNIT && is_string($unit)) {
                $createUnits[$field] = [
                    'code' => 'LB_PER_1000FT',
                    'name' => 'Pound per 1000 feet',
                    'symbol' => 'lb/1000ft',
                    'dimension' => 'linear_density',
                    'confidence' => 'MEDIUM',
                    'evidence' => 'walu/wbnz imperial-only; ratio vs wght (kg/km) — no conversion factor invented',
                ];
            }
            if ($decision === self::PROPOSED_CREATE_OPTIONS) {
                $createOptions[$field] = $p['targetOptions'] ?? null;
            }
        }

        if (($targetScan['units']['KG_PER_KM'] ?? false) === true) {
            $reuseUnits[] = 'KG_PER_KM';
        }

        return [
            'existingDefinitionsToMap' => $mapExisting,
            'newDefinitionsProposed' => $createDefs,
            'existingUnitsToReuse' => $reuseUnits,
            'newUnitsProposed' => $createUnits,
            'newOptionsProposed' => $createOptions,
            'noMutationsInD5' => true,
        ];
    }

    /**
     * @param array<string, mixed> $blockerMatrix
     * @param list<array<string, mixed>> $proposals
     * @return array<string, mixed>
     */
    private function buildNextGate(array $blockerMatrix, array $proposals): array
    {
        $still = [];
        foreach ($blockerMatrix['conceptSummary'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $blocked = (int) ($row['productsBlocked'] ?? 0);
            if ($blocked > 0) {
                $still[(string) ($row['concept'] ?? '')] = $blocked;
            }
        }

        return [
            'gate' => 'CABLE_SPEC_FIELD_MAPPING_READY',
            'currentValue' => 0,
            'requiredForGate' => self::EXPECTED_CABLE_PRODUCTS,
            'conceptsPreventingFullGate' => $still,
            'priority' => ['cc', 'ct', 'walu', 'wbnz', 'ins', 'Af', 'r', 'x50', 'x60', 'tension'],
            'note' => 'cc and ct block all 54 until resolved; walu/wbnz block 14; ins blocks 5; B1 fields block 1.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function filterCableProducts(array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $family = strtoupper((string) ($p['family'] ?? $p['product_family'] ?? ''));
            if ($family === 'CABLE') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<string>
     */
    private function cableStyleCodes(array $products): array
    {
        $styles = [];
        foreach ($products as $p) {
            $s = strtoupper(trim((string) ($p['style_code'] ?? $p['styleCode'] ?? '')));
            if ($s !== '') {
                $styles[] = $s;
            }
        }

        return array_values(array_unique($styles));
    }
}
