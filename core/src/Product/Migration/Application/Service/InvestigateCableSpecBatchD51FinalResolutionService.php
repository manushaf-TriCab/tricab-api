<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.7 Batch D.5.1 — final semantic resolution (read-only).
 */
final class InvestigateCableSpecBatchD51FinalResolutionService
{
    public const DIRECT_EVIDENCE = 'DIRECT_EVIDENCE';
    public const STRONG_CORRELATION = 'STRONG_CORRELATION';
    public const WEAK_CORRELATION = 'WEAK_CORRELATION';
    public const NO_EVIDENCE = 'NO_EVIDENCE';
    public const CONTRADICTORY_EVIDENCE = 'CONTRADICTORY_EVIDENCE';

    public const PROVEN_MAP_EXISTING = 'PROVEN_MAP_EXISTING';
    public const PROVEN_CREATE_DEFINITION = 'PROVEN_CREATE_DEFINITION';
    public const PROVEN_CREATE_UNIT = 'PROVEN_CREATE_UNIT';
    public const PROVEN_CREATE_OPTIONS = 'PROVEN_CREATE_OPTIONS';
    public const PROVEN_NOT_A_SPECIFICATION = 'PROVEN_NOT_A_SPECIFICATION';
    public const EXPLICITLY_UNRESOLVED = 'EXPLICITLY_UNRESOLVED';

    public const D51_BLOCKED_BASELINE_DRIFT = 'D5_1_BLOCKED_BASELINE_DRIFT';

    public const PATH_A = 'PATH_A_FULLY_RESOLVED';
    public const PATH_B = 'PATH_B_RESOLVED_WITH_AUDITED_EXCLUSIONS';
    public const PATH_C = 'PATH_C_MIGRATION_REMAINS_BLOCKED';

    private const EXPECTED_CABLE_PRODUCTS = 54;
    private const ACCESSORY_SPEC_FIELD_MAPPING_READY = 27;

    /** @var list<string> */
    public const REVIEW_FIELDS = InvestigateCableSpecBatchD5ReviewService::REVIEW_FIELDS;

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function resolve(array $inputs): array
    {
        $forbidden = (int) ($inputs['forbiddenWriteAttempts'] ?? 0);
        $azure = is_array($inputs['azure'] ?? null) ? $inputs['azure'] : [];
        $evidence = is_array($inputs['investigationEvidence'] ?? null)
            ? $inputs['investigationEvidence']
            : CableSpecBatchD51InvestigationCollector::embeddedEvidence();
        $sourceScan = is_array($inputs['legacySourceScan'] ?? null)
            ? $inputs['legacySourceScan']
            : CableSpecBatchD51LegacySourceScanner::embeddedScan();
        $d5Package = is_array($inputs['d5Package'] ?? null) ? $inputs['d5Package'] : [];

        $products = $this->filterCableProducts($inputs['classificationReadyProducts'] ?? []);
        $cableStyles = $this->cableStyleCodes($products);
        $presence = $this->mergePresence($evidence, $cableStyles);

        $baseline = $this->validateBaseline($azure, $d5Package);
        if (($baseline['status'] ?? '') === self::D51_BLOCKED_BASELINE_DRIFT) {
            return $this->blockedPackage($baseline, $forbidden);
        }

        $targetScan = $this->scanTargetMaster($azure);
        $fieldFindings = $this->buildFieldFindings($evidence, $sourceScan, $targetScan, $presence);
        $operatorOptions = $this->buildOperatorOptions($fieldFindings);
        $readinessOptions = $this->buildReadinessOptions($products, $presence, $operatorOptions);
        $closure = $this->buildClosureRecommendation($fieldFindings, $operatorOptions);
        $scopedMappingNote = $this->buildScopedMappingNote($fieldFindings);

        $artifacts = [
            'cable-spec-d5-1-cc-final.json',
            'cable-spec-d5-1-ct-final.json',
            'cable-spec-d5-1-weight-unit-final.json',
            'cable-spec-d5-1-ins-final.json',
            'cable-spec-d5-1-b1-final.json',
            'cable-spec-d5-1-operator-options.json',
            'cable-spec-d5-1-readiness-options.json',
            'cable-spec-d5-1-final-package.json',
        ];

        return [
            'phase' => '12.7-batch-d5-1-cable-spec-final-resolution',
            'mode' => 'FINAL_SEMANTIC_INVESTIGATION_ONLY',
            'packageStatus' => $closure['canComplete'] ? 'READY_FOR_FINAL_OPERATOR_DECISIONS' : 'CRITICAL_SEMANTICS_STILL_UNRESOLVED',
            'investigationBanner' => 'FINAL SEMANTIC INVESTIGATION — NOTHING APPLIED',
            'cableSpecApply' => 'NOT_AVAILABLE',
            'approvalBoundary' => [
                'cableSpecD51ApplyGuard' => CableSpecBatchD51CliGuard::APPLY_NOT_AVAILABLE,
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
            'baselineIntegrity' => $baseline,
            'legacySourceScan' => [
                'mode' => $sourceScan['mode'] ?? null,
                'hitCount' => (int) ($sourceScan['hitCount'] ?? 0),
                'directCableSpecsFieldLabels' => $sourceScan['directCableSpecsFieldLabels'] ?? [],
            ],
            'targetMasterScan' => $targetScan,
            'fieldFindings' => $fieldFindings,
            'operatorOptions' => $operatorOptions,
            'readinessOptions' => $readinessOptions,
            'scopedMappingSupport' => $scopedMappingNote,
            'closureRecommendation' => $closure,
            'minimumSafeClosureSet' => $this->buildMinimumClosureSet($fieldFindings),
            'semanticGuards' => [
                'ccCtExclusionNotRecommended' => true,
                'noMediumToHighUpgrade' => true,
                'noApplyInD51' => true,
            ],
            'investigationEvidenceMode' => (string) ($evidence['mode'] ?? 'UNKNOWN'),
            'artifacts' => $artifacts,
            'generatedAtUtc' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $azure
     * @param array<string, mixed> $d5Package
     * @return array<string, mixed>
     */
    private function validateBaseline(array $azure, array $d5Package): array
    {
        $definitions = (int) ($azure['definitionCount'] ?? 0);
        $total = (int) ($azure['fieldMappingCount'] ?? 0);
        $cable = (int) ($azure['cableFieldMappingCount'] ?? 0);
        $d5Ok = ($d5Package['phase'] ?? '') === '12.7-batch-d5-cable-spec-review-investigation'
            || ($d5Package['d4Integrity']['status'] ?? '') === 'D4_INTEGRITY_OK';

        $drift = $definitions !== ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST
            || $total !== ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST
            || $cable !== ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT
            || !$d5Ok;

        return [
            'status' => $drift ? self::D51_BLOCKED_BASELINE_DRIFT : 'BASELINE_OK',
            'definitions' => $definitions,
            'expectedDefinitions' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST,
            'totalFieldMappings' => $total,
            'expectedTotalFieldMappings' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST,
            'cableFieldMappings' => $cable,
            'expectedCableFieldMappings' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
            'd5PackagePresent' => $d5Package !== [],
            'd5IntegrityOk' => $d5Ok,
            'driftDetected' => $drift,
        ];
    }

    /**
     * @param array<string, mixed> $baseline
     * @return array<string, mixed>
     */
    private function blockedPackage(array $baseline, int $forbidden): array
    {
        return [
            'phase' => '12.7-batch-d5-1-cable-spec-final-resolution',
            'packageStatus' => self::D51_BLOCKED_BASELINE_DRIFT,
            'baselineIntegrity' => $baseline,
            'legacySafety' => ['wordpressWrites' => 0, 'tcWrites' => 0, 'forbiddenWriteAttempts' => $forbidden],
            'targetSafety' => ['totalWrites' => 0],
            'generatedAtUtc' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function scanTargetMaster(array $azure): array
    {
        $defs = [];
        foreach ($azure['definitions'] ?? [] as $def) {
            if (!is_array($def)) {
                continue;
            }
            $code = strtoupper(trim((string) ($def['code'] ?? $def['DefinitionCode'] ?? '')));
            if ($code !== '') {
                $defs[$code] = $def;
            }
        }
        $units = [];
        foreach ($azure['units'] ?? [] as $u) {
            if (!is_array($u)) {
                continue;
            }
            $code = strtoupper(trim((string) ($u['code'] ?? $u['UnitCode'] ?? '')));
            if ($code !== '') {
                $units[$code] = $u;
            }
        }

        return [
            'definitionCount' => count($defs) ?: (int) ($azure['definitionCount'] ?? 0),
            'conductorMatches' => array_intersect_key($defs, array_flip([
                'CONDUCTOR-MATERIAL', 'CONDUCTOR-TYPE', 'CONDUCTORS', 'CODE_CONDUCTOR', 'COPPER', 'ALUMINIUM', 'ALUMINUM',
            ])),
            'hasKgPerKm' => isset($units['KG_PER_KM']),
            'hasLbPer1000ft' => isset($units['LB_PER_1000FT']) || isset($units['LB/1000FT']),
            'unitCodes' => array_keys($units),
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $sourceScan
     * @param array<string, mixed> $targetScan
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function buildFieldFindings(
        array $evidence,
        array $sourceScan,
        array $targetScan,
        array $presence,
    ): array {
        $labels = is_array($sourceScan['directCableSpecsFieldLabels'] ?? null)
            ? $sourceScan['directCableSpecsFieldLabels']
            : [];
        $ccBreak = is_array($evidence['ccValueBreakdown'] ?? null) ? $evidence['ccValueBreakdown'] : [];
        $unitProof = is_array($evidence['waluWbnzUnitProof'] ?? null) ? $evidence['waluWbnzUnitProof'] : [];
        $insStyles = is_array($evidence['insPerStyle'] ?? null) ? $evidence['insPerStyle'] : [];
        $b1 = is_array($evidence['b1ElectricalDetail'] ?? null) ? $evidence['b1ElectricalDetail'] : [];

        return [
            'cc' => $this->ccFinding($ccBreak, $labels, $presence),
            'ct' => $this->ctFinding($evidence, $labels, $targetScan, $presence),
            'walu' => $this->weightFinding('walu', 'APPROX-WEIGHT-ALU', 'aluminium', $unitProof, $labels, $presence),
            'wbnz' => $this->weightFinding('wbnz', 'APPROX-WEIGHT-BRONZE', 'bronze', $unitProof, $labels, $presence),
            'ins' => $this->insFinding($insStyles, $labels, $presence),
            'Af' => $this->b1FieldFinding('Af', $b1, $labels, $presence),
            'r' => $this->b1FieldFinding('r', $b1, $labels, $presence),
            'x50' => $this->b1FieldFinding('x50', $b1, $labels, $presence),
            'x60' => $this->b1FieldFinding('x60', $b1, $labels, $presence),
            'tension' => $this->b1FieldFinding('tension', $b1, $labels, $presence),
        ];
    }

    /**
     * @param array<string, mixed> $ccBreak
     * @param array<string, mixed> $labels
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function ccFinding(array $ccBreak, array $labels, array $presence): array
    {
        $valueDetails = [];
        foreach (['c', 'p', 't', 'a'] as $code) {
            $row = is_array($ccBreak[$code] ?? null) ? $ccBreak[$code] : [];
            $meaning = match ($code) {
                'c' => 'cores counting mode — nc counts individual cores',
                'p' => 'pairs counting mode — nc counts pairs',
                't' => 'triples/triads counting mode',
                'a' => 'VUF-only AWG-style xa mode (1/0, 250, 500) — exception not global cores',
                default => 'unknown',
            };
            $valueDetails[] = [
                'code' => $code,
                'meaning' => $meaning,
                'rows' => (int) ($row['rows'] ?? 0),
                'styles' => (int) ($row['styles'] ?? 1),
                'styleSamples' => $row['styleSamples'] ?? ($code === 'a' ? ['VUF'] : []),
                'directEvidence' => null,
                'correlationEvidence' => 'cc×nc distribution aligns with cores/pairs/triples interpretation',
                'confidence' => $code === 'a' ? 'MEDIUM' : 'MEDIUM',
                'evidenceClass' => self::STRONG_CORRELATION,
            ];
        }

        return [
            'meaning' => 'Core-configuration / nc interpretation mode — not a standalone customer dimension',
            'evidenceClass' => self::STRONG_CORRELATION,
            'confidence' => 'MEDIUM',
            'resolution' => self::EXPLICITLY_UNRESOLVED,
            'isCustomerSpecification' => false,
            'safeTargetRepresentation' => 'CORE-CONFIGURATION (candidate) or derive nc interpretation at migration — not proven HIGH',
            'safeExclusion' => false,
            'operatorExclusionNotRecommended' => true,
            'exclusionRisks' => [
                'May misinterpret nc (cores vs pairs vs triples)',
                'cc=a VUF AWG rows would lose encoding',
                'All 54 products populated',
            ],
            'valueBreakdown' => $valueDetails,
            'splitExceptionStrategy' => 'Treat cc=a as VUF-specific AWG mode; c/p/t as nc interpretation metadata',
            'productsBlocked' => self::EXPECTED_CABLE_PRODUCTS,
            'stylesAffected' => count($presence['cc'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $labels
     * @param array<string, mixed> $targetScan
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function ctFinding(array $evidence, array $labels, array $targetScan, array $presence): array
    {
        $ctCmp = is_array($evidence['ctMatchedComparisons'] ?? null) ? $evidence['ctMatchedComparisons'] : [];

        return [
            'meaning' => 'Conductor material encoding — p≈copper default, a≈aluminium (hypothesis)',
            'evidenceClass' => self::STRONG_CORRELATION,
            'confidence' => 'MEDIUM',
            'resolution' => self::EXPLICITLY_UNRESOLVED,
            'directEvidence' => ($labels['ct'] ?? '') !== 'NO_DIRECT_LABEL_IN_SCANNED_REPO' ? $labels['ct'] : null,
            'correlationEvidence' => 'KL ct=a lower wght + larger OD vs ct=p; rating.ct uses C/A not p/a',
            'physicalComparisons' => $ctCmp,
            'targetConductorModels' => $targetScan['conductorMatches'] ?? [],
            'safeTargetRepresentation' => 'CONDUCTOR-MATERIAL with COPPER/ALUMINIUM options — requires operator approval at MEDIUM',
            'safeExclusion' => false,
            'operatorExclusionNotRecommended' => true,
            'exclusionRisks' => [
                'Loses conductor material on all 54 products',
                'May mislabel aluminium vs copper cables',
            ],
            'productsBlocked' => self::EXPECTED_CABLE_PRODUCTS,
        ];
    }

    /**
     * @param array<string, mixed> $unitProof
     * @param array<string, mixed> $labels
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function weightFinding(
        string $field,
        string $defCode,
        string $material,
        array $unitProof,
        array $labels,
        array $presence,
    ): array {
        $directLabel = ($labels[$field] ?? '') !== 'NO_DIRECT_LABEL_IN_SCANNED_REPO' ? $labels[$field] : null;
        if (is_string($directLabel) && (str_contains($directLabel, '=>') || str_contains($directLabel, 'function '))) {
            $directLabel = null;
        }
        $unitClass = self::STRONG_CORRELATION;
        $unitConfidence = 'MEDIUM';

        return [
            'meaning' => "Approximate {$material} component weight (distinct from total wght)",
            'meaningEvidenceClass' => self::STRONG_CORRELATION,
            'meaningConfidence' => 'HIGH',
            'resolution' => self::PROVEN_CREATE_DEFINITION,
            'unit' => 'LB_PER_1000FT',
            'unitEvidenceClass' => $unitClass,
            'unitConfidence' => $unitConfidence,
            'unitEvidence' => [
                'Only populated when mt=1302 (Imperial)',
                'wght/walu mean ~' . ($unitProof['wghtPerWaluMean'] ?? '0.93') . ' — component not total weight',
                'No lb/1000ft string in scanned repo',
                'Hypothesis LB_PER_1000FT — STRONG_CORRELATION not DIRECT_EVIDENCE',
            ],
            'targetDefinition' => $defCode,
            'targetUnit' => 'LB_PER_1000FT',
            'unitResolution' => self::PROVEN_CREATE_UNIT,
            'productsBlocked' => count($presence[$field] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $insStyles
     * @param array<string, mixed> $labels
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function insFinding(array $insStyles, array $labels, array $presence): array
    {
        $perStyle = [];
        foreach (['B1', 'KN', 'NR', 'NV', 'XN'] as $style) {
            $row = is_array($insStyles[$style] ?? null) ? $insStyles[$style] : [];
            $sample = is_array($row['sample'] ?? null) ? $row['sample'] : $row;
            $ins = (float) ($sample['ins'] ?? $row['ins'] ?? 0);
            $odIns = $sample['od_ins'] ?? $row['od_ins'] ?? null;
            $cdia = $sample['cdia'] ?? $row['cdia'] ?? null;
            $computed = $sample['computed_thickness'] ?? $row['computedThickness'] ?? null;
            if ($computed === null && $odIns !== null && $cdia !== null) {
                $computed = ((float) $odIns - (float) $cdia) / 2;
            }
            $derivable = (bool) ($row['derivableFromOdInsCdia'] ?? $row['derivable'] ?? false);
            if (!$derivable && $computed !== null && $ins > 0) {
                $derivable = abs($ins - (float) $computed) < 0.2;
            }
            $perStyle[$style] = [
                'ins' => $sample['ins'] ?? $ins,
                'od_ins' => $odIns,
                'cdia' => $cdia,
                'computedThickness' => $computed,
                'probableMeaning' => match ($style) {
                    'NR', 'NV' => 'insulation thickness mm (derivable)',
                    'KN', 'XN' => 'insulation OD-like mm',
                    'B1' => 'small thickness decimal',
                    default => 'unknown',
                },
                'derivable' => $derivable,
                'resolution' => $derivable ? self::PROVEN_NOT_A_SPECIFICATION : self::EXPLICITLY_UNRESOLVED,
                'reason' => $derivable ? 'DERIVABLE_FROM_EXISTING_DIMENSIONS' : 'STYLE_SPECIFIC_LEGACY_OVERLOAD',
            ];
        }

        return [
            'meaning' => 'STYLE_SPECIFIC_LEGACY_OVERLOAD — not one global Definition',
            'evidenceClass' => self::STRONG_CORRELATION,
            'confidence' => 'MEDIUM',
            'resolution' => self::EXPLICITLY_UNRESOLVED,
            'perStyle' => $perStyle,
            'scopedMappingRequired' => true,
            'productsBlocked' => count($presence['ins'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $b1
     * @param array<string, mixed> $labels
     * @param array<string, list<string>> $presence
     * @return array<string, mixed>
     */
    private function b1FieldFinding(string $field, array $b1, array $labels, array $presence): array
    {
        $direct = ($labels[$field] ?? '') !== 'NO_DIRECT_LABEL_IN_SCANNED_REPO' ? $labels[$field] : null;

        return [
            'meaning' => match ($field) {
                'r' => 'Resistance hypothesis — unit unproven',
                'x50' => 'Reactance at 50 Hz hypothesis',
                'x60' => 'Reactance at 60 Hz hypothesis',
                'tension' => 'Mechanical/pulling tension hypothesis',
                'Af' => 'Unknown electrical scalar — not proven ampacity',
                default => 'unknown',
            },
            'unit' => null,
            'evidenceClass' => $direct !== null ? self::DIRECT_EVIDENCE : self::NO_EVIDENCE,
            'confidence' => 'LOW',
            'resolution' => self::EXPLICITLY_UNRESOLVED,
            'directEvidence' => $direct,
            'b1Context' => $b1,
            'safeToExclude' => true,
            'legacyUnresolvedExcludeCandidate' => true,
            'auditNote' => 'B1-only; raw values preserved in legacy extract; customer experience impact low if excluded',
            'productsBlocked' => count($presence[$field] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $fieldFindings
     * @return list<array<string, mixed>>
     */
    private function buildOperatorOptions(array $fieldFindings): array
    {
        $options = [];
        foreach (self::REVIEW_FIELDS as $field) {
            $f = is_array($fieldFindings[$field] ?? null) ? $fieldFindings[$field] : [];
            $choices = [];
            $choices[] = [
                'id' => 'KEEP_REVIEW',
                'label' => 'Keep REVIEW — no mapping change',
                'readinessImpact' => 'no change',
            ];

            if ($field === 'cc') {
                $choices[] = [
                    'id' => 'APPROVE_CORE_CONFIGURATION',
                    'label' => 'Approve CORE-CONFIGURATION Definition + options (c/p/t + VUF exception for a) at operator MEDIUM acceptance',
                    'readinessImpact' => 'unlocks 54 if ct also resolved',
                ];
                $choices[] = [
                    'id' => 'APPROVE_NOT_A_SPECIFICATION_METADATA',
                    'label' => 'Exclude as nc-interpretation metadata (NOT recommended — OPERATOR_EXCLUSION_NOT_RECOMMENDED)',
                    'readinessImpact' => 'unlocks 54 if ct also resolved — risks nc misread',
                ];
            } elseif ($field === 'ct') {
                $choices[] = [
                    'id' => 'APPROVE_CONDUCTOR_MATERIAL',
                    'label' => 'Approve CONDUCTOR-MATERIAL Definition with COPPER/ALUMINIUM options (MEDIUM evidence)',
                    'readinessImpact' => 'unlocks 54 if cc also resolved',
                ];
                $choices[] = [
                    'id' => 'EXCLUDE_CT_NOT_RECOMMENDED',
                    'label' => 'Exclude conductor type (NOT recommended)',
                    'readinessImpact' => 'would lose material on all 54',
                ];
            } elseif (in_array($field, ['walu', 'wbnz'], true)) {
                $choices[] = [
                    'id' => 'APPROVE_WEIGHT_DEF_AND_UNIT',
                    'label' => 'Approve APPROX-WEIGHT-* Definition + LB_PER_1000FT unit (meaning HIGH, unit MEDIUM)',
                    'readinessImpact' => 'unlocks 14 styles when cc+ct resolved',
                ];
            } elseif ($field === 'ins') {
                $choices[] = [
                    'id' => 'STYLE_SPECIFIC_RESOLUTION',
                    'label' => 'Per-style: NR/NV exclude as derivable; KN/XN/B1 map or review individually',
                    'readinessImpact' => 'unlocks up to 5 styles when cc+ct resolved',
                ];
            } else {
                $choices[] = [
                    'id' => 'LEGACY_UNRESOLVED_EXCLUDE',
                    'label' => 'Audited exclusion LEGACY_UNRESOLVED_EXCLUDE (B1-only obscure field)',
                    'readinessImpact' => 'unlocks 1 style (B1) when other B1/ins blockers cleared',
                ];
            }

            $options[] = [
                'field' => $field,
                'finding' => $f,
                'options' => $choices,
            ];
        }

        return $options;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, list<string>> $presence
     * @param list<array<string, mixed>> $operatorOptions
     * @return array<string, mixed>
     */
    private function buildReadinessOptions(array $products, array $presence, array $operatorOptions): array
    {
        $scenarios = [
            'current' => [],
            'ccCtResolvedMedium' => ['cc', 'ct'],
            'waluWbnzApproved' => ['walu', 'wbnz'],
            'insStyleResolved' => ['ins'],
            'b1Excluded' => ['Af', 'r', 'x50', 'x60', 'tension'],
            'minimumSafeClosure' => ['cc', 'ct', 'walu', 'wbnz', 'ins', 'Af', 'r', 'x50', 'x60', 'tension'],
            'pathAAllProven' => ['cc', 'ct', 'walu', 'wbnz', 'ins'],
        ];

        $out = [];
        foreach ($scenarios as $key => $resolved) {
            $out[$key] = $this->computeReadiness($products, $presence, $resolved);
        }

        $out['perOperatorOption'] = [
            'cc_APPROVE_CORE_CONFIGURATION' => $this->computeReadiness($products, $presence, ['ct']),
            'ct_APPROVE_CONDUCTOR_MATERIAL' => $this->computeReadiness($products, $presence, ['cc']),
            'ccCtBothMediumApproved' => $this->computeReadiness($products, $presence, ['cc', 'ct']),
            'waluWbnzPlusCcCt' => $this->computeReadiness($products, $presence, ['cc', 'ct', 'walu', 'wbnz']),
            'fullClosureSet' => $out['minimumSafeClosure'],
        ];

        return $out;
    }

    /**
     * @param array<string, mixed> $fieldFindings
     * @param list<array<string, mixed>> $operatorOptions
     * @return array<string, mixed>
     */
    private function buildClosureRecommendation(array $fieldFindings, array $operatorOptions): array
    {
        $cc = $fieldFindings['cc'] ?? [];
        $ct = $fieldFindings['ct'] ?? [];
        $ccUnresolved = ($cc['resolution'] ?? '') === self::EXPLICITLY_UNRESOLVED;
        $ctUnresolved = ($ct['resolution'] ?? '') === self::EXPLICITLY_UNRESOLVED;
        $highCount = 0;
        foreach ($fieldFindings as $f) {
            if (is_array($f) && ($f['confidence'] ?? '') === 'HIGH' && ($f['resolution'] ?? '') !== self::EXPLICITLY_UNRESOLVED) {
                ++$highCount;
            }
        }

        if (!$ccUnresolved && !$ctUnresolved && $highCount >= 4) {
            $path = self::PATH_A;
        } elseif ($ccUnresolved || $ctUnresolved) {
            $path = self::PATH_C;
        } else {
            $path = self::PATH_B;
        }

        return [
            'recommendedPath' => $path,
            'canComplete' => true,
            'rationale' => match ($path) {
                self::PATH_A => 'All fields proven to operator-approvable level',
                self::PATH_B => 'Mix of proven mappings and audited exclusions',
                self::PATH_C => 'cc and/or ct lack HIGH-confidence proof — operator must accept MEDIUM mappings or remain blocked',
            },
            'ccCtBlockAll54' => true,
        ];
    }

    /**
     * @param array<string, mixed> $fieldFindings
     * @return array<string, mixed>
     */
    private function buildMinimumClosureSet(array $fieldFindings): array
    {
        return [
            'requiredDecisions' => [
                'cc' => 'Must map or audited exclude — exclusion NOT recommended',
                'ct' => 'Must map or audited exclude — exclusion NOT recommended',
                'walu' => 'PROVEN_CREATE_DEFINITION + PROVEN_CREATE_UNIT (operator approve)',
                'wbnz' => 'PROVEN_CREATE_DEFINITION + PROVEN_CREATE_UNIT (operator approve)',
                'ins' => 'Per-style resolution or exclusion',
                'B1 fields' => 'LEGACY_UNRESOLVED_EXCLUDE candidate per field',
            ],
            'forCableSpecReady54' => 'Resolve cc+ct+walu+wbnz+ins+B1 blockers for affected styles',
            'ccCtOnlyUnlocks' => 35,
            'full54Unlocks' => 54,
        ];
    }

    /**
     * @param array<string, mixed> $fieldFindings
     * @return array<string, mixed>
     */
    private function buildScopedMappingNote(array $fieldFindings): array
    {
        $ins = is_array($fieldFindings['ins'] ?? null) ? $fieldFindings['ins'] : [];

        return [
            'requiredForIns' => (bool) ($ins['scopedMappingRequired'] ?? false),
            'note' => 'ins requires field+style scoped decisions; cc=a requires VUF exception handling',
            'architectureChangeRequired' => false,
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

        return [
            'READY' => $ready,
            'REVIEW' => $review,
            'CABLE_SPEC_FIELD_MAPPING_READY' => $ready,
            'stillBlockingConcepts' => $stillBlocking,
        ];
    }

    /**
     * @param array<string, list<string>> $presence
     */
    private function fieldBlocksStyle(string $field, string $style, array $presence): bool
    {
        if (in_array($field, ['cc', 'ct'], true)) {
            return true;
        }

        return in_array($style, $presence[$field] ?? [], true);
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $cableStyles
     * @return array<string, list<string>>
     */
    private function mergePresence(array $evidence, array $cableStyles): array
    {
        $d5 = is_array($evidence['d5Echo'] ?? null) ? $evidence['d5Echo'] : [];
        $d2 = is_array($d5['d2Echo'] ?? null) ? $d5['d2Echo'] : [];
        $fromD2 = is_array($d2['styleFieldPresence'] ?? null) ? $d2['styleFieldPresence'] : [];

        $presence = [
            'Af' => ['B1'], 'r' => ['B1'], 'x50' => ['B1'], 'x60' => ['B1'], 'tension' => ['B1'],
            'ins' => ['B1', 'KN', 'NR', 'NV', 'XN'],
            'walu' => ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'],
            'wbnz' => ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'],
        ];
        foreach ($fromD2 as $field => $styles) {
            if (is_string($field) && is_array($styles)) {
                $presence[$field] = array_values(array_unique(array_map(
                    static fn ($s): string => strtoupper(trim((string) $s)),
                    $styles,
                )));
            }
        }
        $presence['cc'] = $cableStyles;
        $presence['ct'] = $cableStyles;

        return $presence;
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
            if (strtoupper((string) ($p['family'] ?? '')) === 'CABLE') {
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
