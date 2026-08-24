<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\ClassificationNameNormalizer;
use TriCab\Core\Product\Migration\Classification\LegacyTermSemanticType;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch A.2 — final Category evidence package + Series re-evaluation.
 * Never auto-converts PROPOSED_CREATE → APPROVED_CREATE (operator UI required).
 * Never applies Categories/Series to Azure.
 */
final class ResolveBatchA2ClassificationService
{
    public function __construct(
        private readonly ClassificationNameNormalizer $normalizer = new ClassificationNameNormalizer(),
        private readonly StyleCodeNormalizer $styleNormalizer = new StyleCodeNormalizer(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $categoryItems A.1 resolved items
     * @param list<array<string, mixed>> $seriesItems
     * @param array<string, mixed> $azureSnapshot
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $operatorOverrides
     * @param array<string, mixed> $wpEvidence optional live WP product/overlap evidence
     * @return array<string, mixed>
     */
    public function resolve(
        array $categoryItems,
        array $seriesItems,
        array $azureSnapshot,
        array $environment,
        array $operatorOverrides = [],
        array $wpEvidence = [],
    ): array {
        $families = $this->indexByCode($azureSnapshot['families'] ?? []);
        $categories = $this->indexByCode($azureSnapshot['categories'] ?? []);
        $seriesExisting = $this->indexByCode($azureSnapshot['series'] ?? []);

        $overlap = $this->buildOverlapAnalysis($wpEvidence);
        $resolvedCategories = [];
        $counts = [
            CategoryDecisionState::APPROVED_MAP_EXISTING => 0,
            CategoryDecisionState::APPROVED_CREATE => 0,
            CategoryDecisionState::PROPOSED_CREATE => 0,
            CategoryDecisionState::REVIEW => 0,
            CategoryDecisionState::NOT_A_PRODUCT_CATEGORY => 0,
            CategoryDecisionState::REJECT => 0,
            CategoryDecisionState::CONFLICT => 0,
        ];

        foreach ($categoryItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->resolveCategoryEvidence($item, $categories, $families, $overlap, $wpEvidence, $operatorOverrides);
            $key = (string) ($row['decision'] ?? CategoryDecisionState::REVIEW);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $resolvedCategories[] = $row;
        }

        $accessoryDecisions = $this->accessoryDecisionSummary($resolvedCategories, $overlap);
        $cableDecisions = $this->cableDecisionSummary($resolvedCategories);
        $vfdSourceMappings = array_values(array_filter(
            $resolvedCategories,
            static fn (array $r): bool => ($r['decision'] ?? '') === CategoryDecisionState::APPROVED_MAP_EXISTING
                && strtoupper((string) ($r['targetCategoryCode'] ?? '')) === 'VFD',
        ));

        $approvedCategoryCodes = $this->approvedCategoryCodes($resolvedCategories, $categories);
        $styleToCategory = $this->styleToApprovedCategoryMap($wpEvidence, $resolvedCategories, $approvedCategoryCodes);

        $seriesResolved = $this->resolveSeries(
            $seriesItems,
            $seriesExisting,
            $families,
            $approvedCategoryCodes,
            $styleToCategory,
            $operatorOverrides,
        );

        $approvedSets = [
            'categories' => array_values(array_filter(
                $resolvedCategories,
                static fn (array $r): bool => in_array(
                    $r['decision'] ?? '',
                    [CategoryDecisionState::APPROVED_MAP_EXISTING, CategoryDecisionState::APPROVED_CREATE],
                    true,
                ),
            )),
            'series' => array_values(array_filter(
                $seriesResolved['items'],
                static fn (array $r): bool => in_array(
                    $r['decision'] ?? '',
                    [SeriesDecisionState::APPROVED_MAP_EXISTING, SeriesDecisionState::APPROVED_CREATE],
                    true,
                ),
            )),
        ];

        $preview = $this->buildClassificationPreview($seriesResolved['items'], $approvedSets);

        return [
            'phase' => '12.7-batch-a.2',
            'mode' => 'FINAL_CATEGORY_APPROVAL + SERIES_RESOLUTION',
            'applied' => false,
            'legacyWrites' => 0,
            'productWrites' => 0,
            'variantWrites' => 0,
            'catalogueWrites' => 0,
            'environment' => $environment,
            'targetLiveValidation' => MigrationPayload::string($azureSnapshot['targetLiveValidation'] ?? 'UNAVAILABLE'),
            'targetInspection' => [
                'families' => array_values($families),
                'categories' => array_values($categories),
                'series' => array_values($seriesExisting),
                'productCount' => (int) ($azureSnapshot['productCount'] ?? 0),
                'variantCount' => (int) ($azureSnapshot['variantCount'] ?? 0),
                'catalogueNodeCount' => (int) ($azureSnapshot['catalogueNodeCount'] ?? 0),
                'source' => MigrationPayload::string($azureSnapshot['source'] ?? 'unknown'),
            ],
            'categories' => [
                'counts' => $counts,
                'items' => $resolvedCategories,
                'accessoryDecisions' => $accessoryDecisions,
                'cableDecisions' => $cableDecisions,
                'vfd' => [
                    'sourceMappingCount' => count($vfdSourceMappings),
                    'targetCategoryCount' => count($vfdSourceMappings) > 0 ? 1 : 0,
                    'targetCategoryCode' => 'VFD',
                    'mappings' => $vfdSourceMappings,
                    'applied' => false,
                ],
            ],
            'overlaps' => $overlap,
            'series' => $seriesResolved,
            'productClassificationPreview' => $preview,
            'approvedSets' => $approvedSets,
            'approvalBoundary' => [
                'stopAfterDryRun' => true,
                'applyStatus' => 'NOT RUN',
                'batchAApplyGuard' => 'BATCH_A_APPLY_NOT_APPROVED',
                'proposedCreateIsNotApproval' => true,
                'vfdMappingApplied' => false,
                'finalApplyReadiness' => ($azureSnapshot['targetLiveValidation'] ?? '') === 'AVAILABLE'
                    ? (count($approvedSets['categories']) > 0 || count($approvedSets['series']) > 0
                        ? 'READY_FOR_OPERATOR_APPLY_APPROVAL'
                        : 'CONTINUE_CLASSIFICATION_REVIEW')
                    : 'BLOCKED_TARGET_UNAVAILABLE',
                'recommendation' => count($approvedSets['series']) > 0 && ($counts[CategoryDecisionState::PROPOSED_CREATE] ?? 0) === 0
                    ? 'APPROVE BATCH A APPLY'
                    : 'CONTINUE CLASSIFICATION REVIEW',
                'nextAction' => 'WAIT FOR EXPLICIT OPERATOR APPROVAL',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, array<string, mixed>> $categories
     * @param array<string, array<string, mixed>> $families
     * @param array<string, mixed> $overlap
     * @param array<string, mixed> $wpEvidence
     * @param array<string, mixed> $operatorOverrides
     * @return array<string, mixed>
     */
    private function resolveCategoryEvidence(
        array $item,
        array $categories,
        array $families,
        array $overlap,
        array $wpEvidence,
        array $operatorOverrides,
    ): array {
        $termId = (int) ($item['sourceTermId'] ?? $item['termId'] ?? 0);
        $decoded = MigrationPayload::string($item['decodedName'] ?? $item['rawName'] ?? '');
        $normalized = MigrationPayload::string($item['normalizedName'] ?? $this->normalizer->normalize($decoded));
        $decision = MigrationPayload::string($item['decision'] ?? CategoryDecisionState::REVIEW);
        $reason = MigrationPayload::string($item['reason'] ?? '');
        $family = MigrationPayload::stringOrNull($item['proposedFamily'] ?? null);
        $proposedCode = MigrationPayload::stringOrNull($item['proposedCategoryCode'] ?? null);
        $proposedName = MigrationPayload::stringOrNull($item['proposedCategoryName'] ?? $decoded);
        $targetCode = MigrationPayload::stringOrNull($item['targetCategoryCode'] ?? null);
        $targetPublicId = MigrationPayload::stringOrNull($item['targetCategoryPublicId'] ?? null);
        $semanticType = MigrationPayload::string($item['semanticType'] ?? LegacyTermSemanticType::UNKNOWN);
        $confidence = MigrationPayload::string($item['confidence'] ?? 'low');
        $operatorRecommendation = null;

        $products = $this->productsForTerm($wpEvidence, $termId);

        // --- Cable existing maps (evidence-driven; still not Azure apply) ---
        if ($normalized === 'flexible cables' || $termId === 646) {
            $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
            $targetCode = 'FLEX-POWER';
            $targetPublicId = MigrationPayload::stringOrNull($categories['FLEX-POWER']['publicId'] ?? null);
            $family = 'CABLE';
            $semanticType = LegacyTermSemanticType::PRODUCT_CATEGORY;
            $reason = 'flexible_cable_products_match_existing_flex_power';
            $confidence = 'high';
            $operatorRecommendation = 'MAP_EXISTING_FLEX_POWER';
        } elseif (
            preg_match('/^instrumentation[\s\-–—]/iu', $decoded) === 1
            || preg_match('/^control\s*(and|&)\s*instrumentation$/iu', $decoded) === 1
            || str_starts_with($normalized, 'instrumentation')
            || $normalized === 'control and instrumentation'
        ) {
            $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
            $targetCode = 'INSTRUMENTATION';
            $targetPublicId = MigrationPayload::stringOrNull($categories['INSTRUMENTATION']['publicId'] ?? null);
            $family = 'CABLE';
            $semanticType = LegacyTermSemanticType::PRODUCT_CATEGORY;
            $reason = 'instrumentation_cable_products_map_to_existing_instrumentation';
            $confidence = 'high';
            $operatorRecommendation = 'MAP_EXISTING_INSTRUMENTATION';
        } elseif ($normalized === 'power and control' || preg_match('/^power\s*(and|&)\s*control$/iu', $decoded) === 1) {
            $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
            $targetCode = 'CTRL-MULTI';
            $targetPublicId = MigrationPayload::stringOrNull($categories['CTRL-MULTI']['publicId'] ?? null);
            $family = 'CABLE';
            $semanticType = LegacyTermSemanticType::PRODUCT_CATEGORY;
            $reason = 'power_and_control_products_map_to_existing_ctrl_multi';
            $confidence = 'medium';
            $operatorRecommendation = 'MAP_EXISTING_CTRL_MULTI';
        } elseif (in_array($normalized, ['vfd', 'vfd vsd'], true) && $decision !== CategoryDecisionState::APPROVED_MAP_EXISTING) {
            $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
            $targetCode = 'VFD';
            $targetPublicId = MigrationPayload::stringOrNull($categories['VFD']['publicId'] ?? null);
            $family = 'CABLE';
            $reason = 'vfd_short_or_alias_maps_to_existing_vfd';
            $confidence = 'high';
            $operatorRecommendation = 'MAP_EXISTING_VFD';
        }

        // --- Accessory overlap / hierarchy (recommendations only; no APPROVED_CREATE) ---
        if ($proposedCode === 'FLEX-CONDUIT' || $normalized === 'flexible conduit') {
            $decision = CategoryDecisionState::NOT_A_PRODUCT_CATEGORY;
            $semanticType = LegacyTermSemanticType::CATALOGUE_NAVIGATION;
            $reason = 'overlap_with_conduits_fittings_keep_conduits_fittings_only';
            $confidence = 'high';
            $proposedCode = null;
            $family = null;
            $operatorRecommendation = 'KEEP_CONDUITS_FITTINGS_ONLY';
        } elseif ($proposedCode === 'CONDUIT-GLANDS' || $normalized === 'conduit glands') {
            $decision = CategoryDecisionState::REVIEW;
            $reason = 'distinct_from_glands_but_aligns_with_conduit_fittings_ecosystem';
            $confidence = 'medium';
            $operatorRecommendation = 'ABSORB_INTO_CONDUITS_FITTINGS_OR_SERIES';
            // Remain PROPOSED only if operator later elevates; default REVIEW after evidence.
            $proposedCode = 'CONDUIT-GLANDS';
            $family = 'ACCESSORIES';
        } elseif ($proposedCode === 'BOOTLACE-FERRULE' || $normalized === 'bootlace ferrule') {
            $decision = CategoryDecisionState::REVIEW;
            $reason = 'wp_child_of_terminals_prefer_series_under_terminals';
            $confidence = 'high';
            $operatorRecommendation = 'SERIES_UNDER_TERMINALS';
            $proposedCode = null;
            $family = 'ACCESSORIES';
        } elseif ($proposedCode === 'CONDUITS-FITTINGS') {
            $operatorRecommendation = 'OPERATOR_APPROVE_CREATE_CANDIDATE';
            $decision = CategoryDecisionState::PROPOSED_CREATE;
            $reason = 'accessory_conduit_fittings_product_type_operator_must_approve_create';
            $confidence = 'high';
            $family = 'ACCESSORIES';
        } elseif ($proposedCode === 'GLANDS') {
            $operatorRecommendation = 'OPERATOR_APPROVE_CREATE_CANDIDATE';
            $decision = CategoryDecisionState::PROPOSED_CREATE;
            $reason = 'cable_gland_products_distinct_from_conduit_glands_operator_must_approve_create';
            $confidence = 'high';
            $family = 'ACCESSORIES';
        } elseif ($proposedCode === 'TERMINALS') {
            $operatorRecommendation = 'OPERATOR_APPROVE_CREATE_CANDIDATE';
            $decision = CategoryDecisionState::PROPOSED_CREATE;
            $reason = 'terminal_products_stable_accessory_category_operator_must_approve_create';
            $confidence = 'high';
            $family = 'ACCESSORIES';
        } elseif ($proposedCode === 'TECHNO-CONNECTORS') {
            $operatorRecommendation = 'OPERATOR_APPROVE_CREATE_CANDIDATE_FAMILY_PLUG';
            $decision = CategoryDecisionState::PROPOSED_CREATE;
            $reason = 'techno_connector_products_under_plugs_sockets_parent_prefer_plug_family';
            $confidence = 'high';
            $family = isset($families['PLUG']) ? 'PLUG' : 'ACCESSORIES';
        } elseif ($proposedCode === 'LINKS') {
            $operatorRecommendation = 'OPERATOR_REVIEW_MEDIUM';
            $decision = CategoryDecisionState::PROPOSED_CREATE;
            $reason = 'metallic_link_products_medium_evidence_operator_must_approve_create';
            $confidence = 'medium';
            $family = 'ACCESSORIES';
        } elseif ($proposedCode === 'PLUG-SOCKET-LEADS') {
            $operatorRecommendation = 'OPERATOR_REVIEW_MEDIUM';
            $decision = CategoryDecisionState::PROPOSED_CREATE;
            $reason = 'preassembled_plug_socket_leads_medium_evidence_operator_must_approve_create';
            $confidence = 'medium';
            $family = 'ACCESSORIES';
        }

        // Preserve prior APPROVED_MAP_EXISTING (e.g. VFD/VSD) unless overridden above.
        if (($item['decision'] ?? '') === CategoryDecisionState::APPROVED_MAP_EXISTING
            && $targetCode === null
            && MigrationPayload::stringOrNull($item['targetCategoryCode'] ?? null) !== null
        ) {
            $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
            $targetCode = MigrationPayload::stringOrNull($item['targetCategoryCode'] ?? null);
        }
        if (($item['decision'] ?? '') === CategoryDecisionState::APPROVED_MAP_EXISTING
            && strtoupper((string) ($item['targetCategoryCode'] ?? '')) === 'VFD'
            && !in_array($normalized, ['flexible cables', 'power and control'], true)
            && !str_starts_with($normalized, 'instrumentation')
        ) {
            $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
            $targetCode = 'VFD';
            $reason = MigrationPayload::string($item['reason'] ?? 'exact_normalized_name_match_existing_category');
            $confidence = 'high';
            $family = 'CABLE';
            $operatorRecommendation = 'RETAIN_VFD_MAP_NOT_APPLIED';
        }

        $override = is_array($operatorOverrides['category:' . $termId] ?? null)
            ? $operatorOverrides['category:' . $termId]
            : null;
        if ($override !== null) {
            $decision = MigrationPayload::string($override['decision'] ?? $decision);
            $reason = MigrationPayload::string($override['reason'] ?? $reason);
            $family = MigrationPayload::stringOrNull($override['proposedFamily'] ?? $family);
            $proposedCode = MigrationPayload::stringOrNull($override['proposedCategoryCode'] ?? $proposedCode);
            $proposedName = MigrationPayload::stringOrNull($override['proposedCategoryName'] ?? $proposedName);
            $targetCode = MigrationPayload::stringOrNull($override['targetCategoryCode'] ?? $targetCode);
            // Operator may APPROVED_CREATE only via override/UI — never invent here.
        }

        return [
            'sourceTermId' => $termId,
            'termId' => $termId,
            'rawName' => $item['rawName'] ?? null,
            'decodedName' => $decoded,
            'normalizedName' => $normalized,
            'taxonomy' => $item['taxonomy'] ?? 'category',
            'parentTermId' => (int) ($item['parentTermId'] ?? $item['parent'] ?? 0),
            'postCount' => (int) ($item['postCount'] ?? $item['productUsageCount'] ?? count($products)),
            'sampleProducts' => $products !== [] ? $products : ($item['sampleProducts'] ?? []),
            'styleCodes' => array_values(array_unique(array_filter(array_map(
                static fn (array $p): string => (string) ($p['style_code'] ?? $p['styleCode'] ?? ''),
                $products,
            )))),
            'proposedFamily' => $family,
            'semanticType' => $semanticType,
            'existingTargetCandidate' => $item['existingTargetCandidate'] ?? $item['suggestedExistingCategory'] ?? null,
            'decision' => $decision,
            'proposedCategoryCode' => $proposedCode,
            'proposedCategoryName' => $proposedName,
            'targetCategoryCode' => $targetCode,
            'targetCategoryPublicId' => $targetPublicId,
            'confidence' => $confidence,
            'evidenceScore' => strtoupper($confidence) === 'HIGH' ? 'HIGH' : (strtoupper($confidence) === 'MEDIUM' ? 'MEDIUM' : 'LOW'),
            'evidence' => [
                'wpProducts' => $products,
                'overlapNotes' => $overlap['byTerm'][$termId] ?? null,
                'operatorRecommendation' => $operatorRecommendation,
                'autoApprovedCreate' => false,
            ],
            'reason' => $reason,
            'operatorRecommendation' => $operatorRecommendation,
            'operatorOverride' => $override !== null,
            'conflict' => $decision === CategoryDecisionState::CONFLICT,
            'finalApprovalQueue' => in_array($decision, [
                CategoryDecisionState::PROPOSED_CREATE,
                CategoryDecisionState::APPROVED_MAP_EXISTING,
            ], true),
        ];
    }

    /**
     * @param array<string, mixed> $wpEvidence
     * @return array<string, mixed>
     */
    private function buildOverlapAnalysis(array $wpEvidence): array
    {
        $pairs = is_array($wpEvidence['overlaps'] ?? null) ? $wpEvidence['overlaps'] : [];
        $conduits = $pairs['614_538'] ?? $pairs['conduits_vs_flexible'] ?? null;
        $glands = $pairs['514_519'] ?? $pairs['glands_vs_conduit_glands'] ?? null;
        $bootlace = $pairs['512_602'] ?? $pairs['terminals_vs_bootlace'] ?? null;

        $conduitsShared = is_array($conduits) ? count($conduits) : 0;
        $glandsShared = is_array($glands) ? count($glands) : 0;

        return [
            'conduitsVsFlexibleConduit' => [
                'categoryA' => 'Conduits & Fittings',
                'categoryB' => 'Flexible Conduit',
                'sharedProductCount' => $conduitsShared,
                'sharedProducts' => is_array($conduits) ? $conduits : [],
                'parentTaxonomy' => 'Flexible Conduit parent=0; Conduits & Fittings parent=Cable Management (525)',
                'semanticRelationship' => 'near_complete_product_set_overlap',
                'decision' => 'KEEP_CONDUITS_FITTINGS_ONLY',
                'reason' => 'Flexible Conduit product set is a near-subset of Conduits & Fittings; TriCab has no recursive Category hierarchy.',
            ],
            'glandsVsConduitGlands' => [
                'categoryA' => 'Glands',
                'categoryB' => 'Conduit Glands',
                'sharedProductCount' => $glandsShared,
                'sharedProducts' => is_array($glands) ? $glands : [],
                'parentTaxonomy' => 'Both parent=Accessories (508)',
                'semanticRelationship' => 'sibling_terms_disjoint_product_sets',
                'decision' => 'KEEP_GLANDS_CATEGORY_CONDUIT_GLANDS_REVIEW',
                'reason' => 'Zero shared Products; Glands are cable-entry glands (D-series) while Conduit Glands are conduit connectors (F01–F04) aligned with conduit fittings.',
            ],
            'terminalsVsBootlace' => [
                'categoryA' => 'Terminals',
                'categoryB' => 'Bootlace / Ferrule',
                'sharedProductCount' => is_array($bootlace) ? count($bootlace) : 0,
                'parentTaxonomy' => 'Bootlace parent=Terminals (512)',
                'semanticRelationship' => 'wp_parent_child',
                'decision' => 'BOOTLACE_IS_SERIES_UNDER_TERMINALS',
                'reason' => 'Bootlace is a WP child of Terminals with disjoint Products — prefer Series under Terminals, not a second Category.',
            ],
            'byTerm' => [],
            'pairs' => $pairs,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $overlap
     * @return array<string, mixed>
     */
    private function accessoryDecisionSummary(array $items, array $overlap): array
    {
        $byCode = [];
        foreach ($items as $item) {
            $code = strtoupper((string) ($item['proposedCategoryCode'] ?? $item['operatorRecommendation'] ?? ''));
            $norm = (string) ($item['normalizedName'] ?? '');
            $map = [
                'conduits and fittings' => 'CONDUITS-FITTINGS',
                'flexible conduit' => 'FLEX-CONDUIT',
                'techno connectors' => 'TECHNO-CONNECTORS',
                'terminals' => 'TERMINALS',
                'bootlace ferrule' => 'BOOTLACE-FERRULE',
                'glands' => 'GLANDS',
                'conduit glands' => 'CONDUIT-GLANDS',
                'links' => 'LINKS',
                'plugsocket leads' => 'PLUG-SOCKET-LEADS',
                'plug socket leads' => 'PLUG-SOCKET-LEADS',
            ];
            $key = $map[$norm] ?? ($item['proposedCategoryCode'] ?? null);
            if ($key === null) {
                continue;
            }
            $byCode[(string) $key] = [
                'decision' => $item['decision'] ?? null,
                'operatorRecommendation' => $item['operatorRecommendation'] ?? null,
                'reason' => $item['reason'] ?? null,
                'family' => $item['proposedFamily'] ?? null,
                'approvedCreate' => ($item['decision'] ?? '') === CategoryDecisionState::APPROVED_CREATE,
                'sourceTermId' => $item['sourceTermId'] ?? null,
            ];
        }

        return [
            'CONDUITS-FITTINGS' => $byCode['CONDUITS-FITTINGS'] ?? ['decision' => 'UNKNOWN'],
            'FLEX-CONDUIT' => $byCode['FLEX-CONDUIT'] ?? [
                'decision' => CategoryDecisionState::NOT_A_PRODUCT_CATEGORY,
                'operatorRecommendation' => $overlap['conduitsVsFlexibleConduit']['decision'] ?? null,
            ],
            'TECHNO-CONNECTORS' => $byCode['TECHNO-CONNECTORS'] ?? ['decision' => 'UNKNOWN'],
            'TERMINALS' => $byCode['TERMINALS'] ?? ['decision' => 'UNKNOWN'],
            'BOOTLACE-FERRULE' => $byCode['BOOTLACE-FERRULE'] ?? ['decision' => 'UNKNOWN'],
            'GLANDS' => $byCode['GLANDS'] ?? ['decision' => 'UNKNOWN'],
            'CONDUIT-GLANDS' => $byCode['CONDUIT-GLANDS'] ?? ['decision' => 'UNKNOWN'],
            'LINKS' => $byCode['LINKS'] ?? ['decision' => 'UNKNOWN'],
            'PLUG-SOCKET-LEADS' => $byCode['PLUG-SOCKET-LEADS'] ?? ['decision' => 'UNKNOWN'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function cableDecisionSummary(array $items): array
    {
        $pick = static function (array $items, callable $pred): array {
            $out = [];
            foreach ($items as $item) {
                if ($pred($item)) {
                    $out[] = [
                        'sourceTermId' => $item['sourceTermId'] ?? null,
                        'decodedName' => $item['decodedName'] ?? null,
                        'decision' => $item['decision'] ?? null,
                        'targetCategoryCode' => $item['targetCategoryCode'] ?? null,
                        'reason' => $item['reason'] ?? null,
                        'confidence' => $item['confidence'] ?? null,
                    ];
                }
            }

            return $out;
        };

        return [
            'Flexible Cables → FLEX-POWER' => $pick($items, static fn (array $i): bool => ($i['normalizedName'] ?? '') === 'flexible cables'),
            'Instrumentation → INSTRUMENTATION' => $pick($items, static fn (array $i): bool => str_contains((string) ($i['normalizedName'] ?? ''), 'instrumentation') || str_contains((string) ($i['normalizedName'] ?? ''), 'control and instrumentation')),
            'Power and Control → CTRL-MULTI' => $pick($items, static fn (array $i): bool => ($i['normalizedName'] ?? '') === 'power and control'),
            'VFD/VSD → VFD' => $pick($items, static fn (array $i): bool => strtoupper((string) ($i['targetCategoryCode'] ?? '')) === 'VFD'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, array<string, mixed>> $categories
     * @return array<string, true>
     */
    private function approvedCategoryCodes(array $items, array $categories): array
    {
        $out = [];
        foreach ($categories as $code => $_) {
            $out[$code] = true;
        }
        foreach ($items as $item) {
            $decision = $item['decision'] ?? '';
            if ($decision === CategoryDecisionState::APPROVED_MAP_EXISTING && !empty($item['targetCategoryCode'])) {
                $out[strtoupper((string) $item['targetCategoryCode'])] = true;
            }
            if ($decision === CategoryDecisionState::APPROVED_CREATE && !empty($item['proposedCategoryCode'])) {
                $out[strtoupper((string) $item['proposedCategoryCode'])] = true;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $wpEvidence
     * @param list<array<string, mixed>> $resolvedCategories
     * @param array<string, true> $approvedCategoryCodes
     * @return array<string, string> style => categoryCode
     */
    private function styleToApprovedCategoryMap(
        array $wpEvidence,
        array $resolvedCategories,
        array $approvedCategoryCodes,
    ): array {
        $termToCategory = [];
        foreach ($resolvedCategories as $item) {
            if (($item['decision'] ?? '') !== CategoryDecisionState::APPROVED_MAP_EXISTING
                && ($item['decision'] ?? '') !== CategoryDecisionState::APPROVED_CREATE
            ) {
                continue;
            }
            $code = strtoupper((string) ($item['targetCategoryCode'] ?? $item['proposedCategoryCode'] ?? ''));
            if ($code === '' || !isset($approvedCategoryCodes[$code])) {
                continue;
            }
            $termToCategory[(int) ($item['sourceTermId'] ?? 0)] = $code;
        }

        $map = [];
        $byTerm = is_array($wpEvidence['productsByTerm'] ?? null) ? $wpEvidence['productsByTerm'] : [];
        foreach ($byTerm as $termId => $products) {
            $cat = $termToCategory[(int) $termId] ?? null;
            if ($cat === null || !is_array($products)) {
                continue;
            }
            foreach ($products as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $style = strtoupper(trim((string) ($p['style_code'] ?? $p['styleCode'] ?? '')));
                if ($style === '' || str_contains($style, ',')) {
                    continue;
                }
                $map[$style] = $cat;
            }
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $seriesItems
     * @param array<string, array<string, mixed>> $seriesExisting
     * @param array<string, array<string, mixed>> $families
     * @param array<string, true> $approvedCategoryCodes
     * @param array<string, string> $styleToCategory
     * @param array<string, mixed> $operatorOverrides
     * @return array<string, mixed>
     */
    private function resolveSeries(
        array $seriesItems,
        array $seriesExisting,
        array $families,
        array $approvedCategoryCodes,
        array $styleToCategory,
        array $operatorOverrides,
    ): array {
        $counts = [
            SeriesDecisionState::APPROVED_MAP_EXISTING => 0,
            SeriesDecisionState::APPROVED_CREATE => 0,
            SeriesDecisionState::PROPOSED_CREATE => 0,
            SeriesDecisionState::REVIEW => 0,
            SeriesDecisionState::INVALID => 0,
            SeriesDecisionState::SOURCE_ONLY => 0,
            SeriesDecisionState::REJECT => 0,
            SeriesDecisionState::CONFLICT => 0,
        ];
        $cardinality = [];
        foreach ($seriesItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = MigrationPayload::string($row['styleCode'] ?? $row['legacyStyleCode'] ?? '');
            $cardinality[$raw] = ($cardinality[$raw] ?? 0) + 1;
        }

        $items = [];
        $proposedCodes = [];
        foreach ($seriesExisting as $code => $_) {
            $proposedCodes[$code] = true;
        }

        foreach ($seriesItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = MigrationPayload::string($row['styleCode'] ?? $row['legacyStyleCode'] ?? '');
            $style = $this->styleNormalizer->normalize($raw);
            $family = strtoupper(MigrationPayload::string($row['family'] ?? ''));
            $categoryCode = MigrationPayload::stringOrNull($row['categoryCode'] ?? $row['category'] ?? null);
            if (($categoryCode === null || $categoryCode === '') && $style['normalized'] !== null) {
                $categoryCode = $styleToCategory[$style['normalized']] ?? null;
            }
            $title = MigrationPayload::string($row['productTitle'] ?? $row['proposedSeriesName'] ?? '');
            $proposedCode = $style['normalized'];
            $proposedName = $title !== '' ? $title : ($style['normalized'] ?? $raw);
            $decision = SeriesDecisionState::REVIEW;
            $reason = 'category_unresolved_or_insufficient_series_evidence';
            $confidence = 'low';
            $targetPublicId = null;

            if ($style['isBlank']) {
                $decision = SeriesDecisionState::INVALID;
                $reason = 'blank_style_code';
            } elseif ($style['isCommaSeparated']) {
                $decision = SeriesDecisionState::INVALID;
                $reason = 'COMPOSITE_STYLE_CODE';
            } elseif ($style['isNumeric']) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'numeric_tc_cable_style_uncertain_not_auto_series';
            } elseif ($proposedCode !== null && isset($seriesExisting[$proposedCode])) {
                $existing = $seriesExisting[$proposedCode];
                $existingCat = strtoupper((string) ($existing['categoryCode'] ?? ''));
                if ($categoryCode !== null && $existingCat !== '' && $existingCat !== strtoupper($categoryCode)) {
                    $decision = SeriesDecisionState::CONFLICT;
                    $reason = 'existing_series_category_mismatch';
                } else {
                    $decision = SeriesDecisionState::APPROVED_MAP_EXISTING;
                    $reason = 'exact_series_code_match';
                    $confidence = 'high';
                    $targetPublicId = MigrationPayload::stringOrNull($existing['publicId'] ?? null);
                    $proposedName = MigrationPayload::string($existing['name'] ?? $proposedName);
                    if ($categoryCode === null || $categoryCode === '') {
                        $categoryCode = $existingCat !== '' ? $existingCat : $categoryCode;
                    }
                }
            } elseif ($family !== '' && !isset($families[$family])) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'family_unknown_on_target';
            } elseif ($categoryCode === null || $categoryCode === '' || !isset($approvedCategoryCodes[strtoupper($categoryCode)])) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'parent_category_not_operator_approved';
            } elseif (($row['identityStatus'] ?? null) === 'SOURCE_ONLY') {
                $decision = SeriesDecisionState::SOURCE_ONLY;
                $reason = 'source_only_identity_insufficient_for_series_create';
            } elseif ($proposedCode !== null && isset($proposedCodes[$proposedCode]) && !isset($seriesExisting[$proposedCode])) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'series_code_collision_no_silent_suffix';
            } else {
                $decision = SeriesDecisionState::PROPOSED_CREATE;
                $reason = 'eligible_pending_operator_approve_create';
                $confidence = 'medium';
                if ($proposedCode !== null) {
                    $proposedCodes[$proposedCode] = true;
                }
            }

            $override = is_array($operatorOverrides['series:' . strtoupper($raw)] ?? null)
                ? $operatorOverrides['series:' . strtoupper($raw)]
                : null;
            if ($override !== null) {
                $decision = MigrationPayload::string($override['decision'] ?? $decision);
                $reason = MigrationPayload::string($override['reason'] ?? $reason);
            }

            if ($decision === SeriesDecisionState::APPROVED_CREATE
                && ($categoryCode === null || !isset($approvedCategoryCodes[strtoupper((string) $categoryCode)]))
            ) {
                $decision = SeriesDecisionState::CONFLICT;
                $reason = 'series_create_requires_approved_category';
            }

            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
            $items[] = [
                'styleCode' => $raw,
                'styleNormalized' => $style['normalized'],
                'productTitle' => $title,
                'postId' => $row['postId'] ?? $row['legacyPostId'] ?? null,
                'family' => $family !== '' ? $family : null,
                'category' => $categoryCode,
                'categoryCode' => $categoryCode,
                'tcMatch' => $row['tcMatch'] ?? $row['tproMatch'] ?? null,
                'variantCount' => (int) ($row['variantCount'] ?? 0),
                'existingTargetSeries' => $proposedCode !== null && isset($seriesExisting[$proposedCode]) ? $proposedCode : ($row['existingSeriesMatch'] ?? null),
                'proposedCode' => $proposedCode,
                'proposedName' => $proposedName,
                'proposedSeriesCode' => $proposedCode,
                'proposedSeriesName' => $proposedName,
                'targetSeriesPublicId' => $targetPublicId,
                'decision' => $decision,
                'confidence' => $confidence,
                'cardinalitySameStyle' => $cardinality[$raw] ?? 1,
                'evidence' => [
                    'styleIssues' => $style['issues'],
                    'styleStatus' => $style['status'],
                    'categorySource' => isset($styleToCategory[$style['normalized'] ?? '']) ? 'wp_term_approved_map' : 'series_row',
                ],
                'reason' => $reason,
                'conflict' => $decision === SeriesDecisionState::CONFLICT,
            ];
        }

        $multiStyle = count(array_filter($cardinality, static fn (int $n): bool => $n > 1));

        return [
            'counts' => $counts,
            'items' => $items,
            'cardinality' => [
                'uniqueStyles' => count($cardinality),
                'stylesWithMultipleProducts' => $multiStyle,
                'oneProductOneStyle' => count($cardinality) - $multiStyle,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $seriesItems
     * @param array{categories: list<array<string, mixed>>, series: list<array<string, mixed>>} $approvedSets
     * @return array<string, mixed>
     */
    private function buildClassificationPreview(array $seriesItems, array $approvedSets): array
    {
        $approvedSeriesCodes = [];
        foreach ($approvedSets['series'] as $s) {
            $code = strtoupper((string) ($s['proposedCode'] ?? $s['styleCode'] ?? ''));
            if ($code !== '') {
                $approvedSeriesCodes[$code] = true;
            }
        }

        $counts = [
            'CLASSIFICATION_READY' => 0,
            'CLASSIFICATION_REVIEW' => 0,
            'CLASSIFICATION_INVALID' => 0,
        ];
        $reasons = [
            'CATEGORY_UNRESOLVED' => 0,
            'SERIES_UNRESOLVED' => 0,
            'INVALID_STYLE' => 0,
            'COMPOSITE_STYLE' => 0,
            'AMBIGUOUS_TAXONOMY' => 0,
            'SOURCE_ONLY' => 0,
            'ACCESSORY_IDENTITY' => 0,
            'TARGET_CONFLICT' => 0,
            'OTHER' => 0,
        ];
        $items = [];
        foreach ($seriesItems as $s) {
            $decision = (string) ($s['decision'] ?? '');
            $reason = (string) ($s['reason'] ?? '');
            $status = 'CLASSIFICATION_REVIEW';
            if ($decision === SeriesDecisionState::INVALID || $decision === SeriesDecisionState::REJECT) {
                $status = 'CLASSIFICATION_INVALID';
            } elseif (in_array($decision, [SeriesDecisionState::APPROVED_MAP_EXISTING, SeriesDecisionState::APPROVED_CREATE], true)) {
                $status = 'CLASSIFICATION_READY';
            }

            $bucket = match (true) {
                $reason === 'COMPOSITE_STYLE_CODE' => 'COMPOSITE_STYLE',
                str_contains($reason, 'numeric') => 'INVALID_STYLE',
                str_contains($reason, 'blank_style') => 'INVALID_STYLE',
                str_contains($reason, 'parent_category') => 'CATEGORY_UNRESOLVED',
                $decision === SeriesDecisionState::PROPOSED_CREATE || str_contains($reason, 'eligible_pending') => 'SERIES_UNRESOLVED',
                $decision === SeriesDecisionState::SOURCE_ONLY => 'SOURCE_ONLY',
                $decision === SeriesDecisionState::CONFLICT => 'TARGET_CONFLICT',
                str_contains($reason, 'ambiguous') => 'AMBIGUOUS_TAXONOMY',
                str_contains($reason, 'accessory') => 'ACCESSORY_IDENTITY',
                $status === 'CLASSIFICATION_READY' => 'OTHER',
                default => 'OTHER',
            };
            if ($status !== 'CLASSIFICATION_READY') {
                $reasons[$bucket] = ($reasons[$bucket] ?? 0) + 1;
            }

            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $items[] = [
                'product' => $s['productTitle'] ?? null,
                'postId' => $s['postId'] ?? null,
                'style_code' => $s['styleCode'] ?? null,
                'family' => $s['family'] ?? null,
                'category' => $s['category'] ?? $s['categoryCode'] ?? null,
                'series' => $s['proposedCode'] ?? null,
                'classificationStatus' => $status,
                'reason' => $reason,
                'reviewReasonBucket' => $status === 'CLASSIFICATION_READY' ? null : $bucket,
            ];
        }

        return ['counts' => $counts, 'reviewReasons' => $reasons, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $wpEvidence
     * @return list<array<string, mixed>>
     */
    private function productsForTerm(array $wpEvidence, int $termId): array
    {
        $byTerm = $wpEvidence['productsByTerm'][$termId] ?? $wpEvidence['productsByTerm'][(string) $termId] ?? [];

        return is_array($byTerm) ? array_values(array_filter($byTerm, 'is_array')) : [];
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
            $code = strtoupper(MigrationPayload::string($row['code'] ?? $row['Code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'code' => $code,
                'name' => MigrationPayload::string($row['name'] ?? $row['Name'] ?? ''),
                'publicId' => MigrationPayload::stringOrNull($row['publicId'] ?? $row['pid'] ?? null),
                'familyCode' => MigrationPayload::stringOrNull($row['familyCode'] ?? $row['family'] ?? null),
                'categoryCode' => MigrationPayload::stringOrNull($row['categoryCode'] ?? $row['category'] ?? null),
                'isActive' => $row['isActive'] ?? $row['active'] ?? true,
                'deleted' => $row['deleted'] ?? false,
            ];
        }

        return $out;
    }
}
