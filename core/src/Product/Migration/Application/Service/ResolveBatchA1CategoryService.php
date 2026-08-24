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
 * Phase 12.7 Batch A.1 — resolve REVIEW Category candidates with evidence;
 * re-evaluate Series after Category decisions. No target writes.
 */
final class ResolveBatchA1CategoryService
{
    public function __construct(
        private readonly ClassificationNameNormalizer $normalizer = new ClassificationNameNormalizer(),
        private readonly StyleCodeNormalizer $styleNormalizer = new StyleCodeNormalizer(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $reviewItems Batch A REVIEW category rows
     * @param list<array<string, mixed>> $allCategoryItems full Batch A category items (incl non-review)
     * @param list<array<string, mixed>> $duplicateGroups
     * @param list<array<string, mixed>> $seriesItems
     * @param array<string, mixed> $azureSnapshot
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $operatorOverrides
     * @return array<string, mixed>
     */
    public function resolve(
        array $reviewItems,
        array $allCategoryItems,
        array $duplicateGroups,
        array $seriesItems,
        array $azureSnapshot,
        array $environment,
        array $operatorOverrides = [],
    ): array {
        $families = $this->indexByCode($azureSnapshot['families'] ?? []);
        $categories = $this->indexByCode($azureSnapshot['categories'] ?? []);
        $categoriesByName = [];
        foreach ($categories as $cat) {
            $categoriesByName[$this->normalizer->normalize((string) ($cat['name'] ?? ''))] = $cat;
        }
        $seriesExisting = $this->indexByCode($azureSnapshot['series'] ?? []);

        $resolved = [];
        $counts = [
            CategoryDecisionState::APPROVED_MAP_EXISTING => 0,
            CategoryDecisionState::APPROVED_CREATE => 0,
            CategoryDecisionState::PROPOSED_CREATE => 0,
            CategoryDecisionState::REVIEW => 0,
            CategoryDecisionState::NOT_A_PRODUCT_CATEGORY => 0,
            CategoryDecisionState::REJECT => 0,
            CategoryDecisionState::CONFLICT => 0,
        ];

        // Preserve non-REVIEW decisions from Batch A (e.g. VFD map, NOT_A_PRODUCT_CATEGORY).
        $carryForward = [];
        foreach ($allCategoryItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $decision = MigrationPayload::string($item['decision'] ?? '');
            if ($decision === CategoryDecisionState::REVIEW || $decision === '') {
                continue;
            }
            $carryForward[] = $item;
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
        }

        $proposedCodesUsed = [];
        foreach ($categories as $code => $_) {
            $proposedCodesUsed[$code] = true;
        }

        foreach ($reviewItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->resolveOneCategory($item, $families, $categories, $categoriesByName, $proposedCodesUsed, $operatorOverrides);
            if (($row['decision'] ?? '') === CategoryDecisionState::PROPOSED_CREATE
                && !empty($row['proposedCategoryCode'])
            ) {
                $code = strtoupper((string) $row['proposedCategoryCode']);
                if (isset($proposedCodesUsed[$code])) {
                    $row['decision'] = CategoryDecisionState::REVIEW;
                    $row['reason'] = 'category_code_collision_no_silent_suffix';
                    $row['confidence'] = 'low';
                    $row['conflict'] = true;
                } else {
                    $proposedCodesUsed[$code] = true;
                }
            }
            $decisionKey = (string) ($row['decision'] ?? '');
            $counts[$decisionKey] = ($counts[$decisionKey] ?? 0) + 1;
            $resolved[] = $row;
        }

        $dupResolved = $this->resolveDuplicates($duplicateGroups, array_merge($carryForward, $resolved));
        $approvedCategoryCodes = $this->approvedCategoryCodes(array_merge($carryForward, $resolved), $categories);
        $seriesResolved = $this->resolveSeries($seriesItems, $seriesExisting, $families, $approvedCategoryCodes, $operatorOverrides);

        $proposedCreates = array_values(array_filter(
            $resolved,
            static fn (array $r): bool => ($r['decision'] ?? '') === CategoryDecisionState::PROPOSED_CREATE,
        ));
        $approvedSets = [
            'categories' => array_values(array_filter(
                array_merge($carryForward, $resolved),
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

        return [
            'phase' => '12.7-batch-a.1',
            'mode' => 'CATEGORY_RESOLUTION + SERIES_RE_EVALUATION',
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
                'source' => MigrationPayload::string($azureSnapshot['source'] ?? 'discovery-cache'),
            ],
            'categories' => [
                'previousReview' => count($reviewItems),
                'counts' => $counts,
                'carryForward' => $carryForward,
                'resolvedReview' => $resolved,
                'proposedCreates' => $proposedCreates,
            ],
            'duplicates' => $dupResolved,
            'series' => $seriesResolved,
            'approvedSets' => $approvedSets,
            'approvalBoundary' => [
                'stopAfterResolution' => true,
                'applyStatus' => 'NOT RUN',
                'vfdMappingApplied' => false,
                'proposedCreateIsNotApproval' => true,
                'finalApplyReadiness' => ($azureSnapshot['targetLiveValidation'] ?? '') === 'AVAILABLE'
                    ? 'READY_FOR_OPERATOR_APPLY_APPROVAL'
                    : 'BLOCKED_TARGET_UNAVAILABLE',
                'nextAction' => 'WAIT FOR EXPLICIT FINAL BATCH A APPLY APPROVAL',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, array<string, mixed>> $families
     * @param array<string, array<string, mixed>> $categories
     * @param array<string, array<string, mixed>> $categoriesByName
     * @param array<string, bool> $proposedCodesUsed
     * @param array<string, mixed> $operatorOverrides
     * @return array<string, mixed>
     */
    private function resolveOneCategory(
        array $item,
        array $families,
        array $categories,
        array $categoriesByName,
        array &$proposedCodesUsed,
        array $operatorOverrides,
    ): array {
        unset($proposedCodesUsed);
        $termId = (int) ($item['termId'] ?? 0);
        $decoded = MigrationPayload::string($item['decodedName'] ?? $item['rawName'] ?? '');
        $normalized = MigrationPayload::string($item['normalizedName'] ?? $this->normalizer->normalize($decoded));
        $usage = (int) ($item['productUsageCount'] ?? 0);
        $override = is_array($operatorOverrides['category:' . $termId] ?? null)
            ? $operatorOverrides['category:' . $termId]
            : null;

        $refined = $this->refineSemantic($decoded, $normalized);
        $semanticType = $refined['semanticType'];
        $family = $this->suggestFamily($decoded, $semanticType, $families);
        $decision = CategoryDecisionState::REVIEW;
        $reason = $refined['reason'];
        $confidence = 'low';
        $evidenceScore = 'LOW';
        $proposedCode = null;
        $proposedName = $decoded !== '' ? $decoded : null;
        $targetCode = null;
        $targetPublicId = null;
        $existingCandidate = $item['suggestedExistingCategory'] ?? null;

        // Exact name match → APPROVED_MAP_EXISTING (operator still must apply separately).
        if ($semanticType === LegacyTermSemanticType::PRODUCT_CATEGORY && isset($categoriesByName[$normalized])) {
            $match = $categoriesByName[$normalized];
            $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
            $targetCode = MigrationPayload::string($match['code'] ?? '');
            $targetPublicId = MigrationPayload::stringOrNull($match['publicId'] ?? null);
            $family = MigrationPayload::string($match['familyCode'] ?? $family ?? '');
            $reason = 'exact_normalized_name_match_existing_category';
            $confidence = 'high';
            $evidenceScore = 'HIGH';
        } elseif ($semanticType !== LegacyTermSemanticType::PRODUCT_CATEGORY) {
            $decision = match ($semanticType) {
                LegacyTermSemanticType::OBSOLETE => CategoryDecisionState::REJECT,
                default => CategoryDecisionState::NOT_A_PRODUCT_CATEGORY,
            };
            $reason = 'semantic_type_' . strtolower($semanticType);
            $confidence = $refined['confidence'];
            $evidenceScore = $confidence === 'high' ? 'HIGH' : 'MEDIUM';
            $family = null;
        } else {
            // Strong accessory/cable product-type proposals (not auto-approved).
            $proposal = $this->proposeCreate($decoded, $normalized, $usage, $family, $families);
            if ($proposal !== null) {
                $decision = CategoryDecisionState::PROPOSED_CREATE;
                $proposedCode = $proposal['code'];
                $proposedName = $proposal['name'];
                $family = $proposal['family'];
                $reason = $proposal['reason'];
                $confidence = $proposal['confidence'];
                $evidenceScore = $proposal['evidenceScore'];
            } else {
                // Near existing? keep REVIEW with suggestion.
                $fuzzy = $this->fuzzyExisting($normalized, $categoriesByName);
                if ($fuzzy !== null) {
                    $existingCandidate = $fuzzy;
                    $reason = 'product_category_candidate_with_suggested_match_operator_must_map';
                    $confidence = 'medium';
                    $evidenceScore = 'MEDIUM';
                } else {
                    $reason = 'ambiguous_or_insufficient_evidence_for_create';
                    $confidence = 'low';
                    $evidenceScore = 'LOW';
                }
            }
        }

        if ($override !== null) {
            $decision = MigrationPayload::string($override['decision'] ?? $decision);
            $reason = MigrationPayload::string($override['reason'] ?? $reason);
            $family = MigrationPayload::stringOrNull($override['proposedFamily'] ?? $family);
            $proposedCode = MigrationPayload::stringOrNull($override['proposedCategoryCode'] ?? $proposedCode);
            $proposedName = MigrationPayload::stringOrNull($override['proposedCategoryName'] ?? $proposedName);
            $targetCode = MigrationPayload::stringOrNull($override['targetCategoryCode'] ?? $targetCode);
            $confidence = MigrationPayload::string($override['confidence'] ?? $confidence);
        }

        return [
            'sourceTermId' => $termId,
            'rawName' => $item['rawName'] ?? null,
            'decodedName' => $decoded,
            'normalizedName' => $normalized,
            'taxonomy' => $item['taxonomy'] ?? 'category',
            'parentTermId' => (int) ($item['parentTermId'] ?? $item['parent'] ?? 0),
            'postCount' => $usage,
            'postTypes' => $item['relatedPostTypes'] ?? $item['postTypes'] ?? null,
            'sampleProducts' => $item['sampleProducts'] ?? [],
            'styleCodes' => $item['styleCodes'] ?? [],
            'proposedFamily' => $family,
            'semanticType' => $semanticType,
            'existingTargetCandidate' => $existingCandidate,
            'duplicateGroup' => $normalized,
            'decision' => $decision,
            'proposedCategoryCode' => $proposedCode,
            'proposedCategoryName' => $proposedName,
            'targetCategoryCode' => $targetCode,
            'targetCategoryPublicId' => $targetPublicId,
            'confidence' => $confidence,
            'evidenceScore' => $evidenceScore,
            'evidence' => [
                'usageCount' => $usage,
                'familyInference' => $family,
                'refinedSemantic' => $refined,
                'note' => 'Product-level sample enrichment requires live WP read; decision uses taxonomy + naming evidence only when samples unavailable.',
            ],
            'reason' => $reason,
            'conflict' => $decision === CategoryDecisionState::CONFLICT,
            'operatorOverride' => $override !== null,
        ];
    }

    /**
     * @return array{semanticType: string, reason: string, confidence: string}
     */
    private function refineSemantic(string $decoded, string $normalized): array
    {
        if (preg_match('/certificate|approval|cpt/i', $decoded) === 1) {
            return ['semanticType' => LegacyTermSemanticType::APPROVAL, 'reason' => 'certificate_or_approval_taxonomy', 'confidence' => 'high'];
        }
        if (preg_match('/\b(as\/nzs|iec|ul|nek|standard)\b/i', $decoded) === 1) {
            return ['semanticType' => LegacyTermSemanticType::STANDARD, 'reason' => 'standards_taxonomy', 'confidence' => 'high'];
        }
        if (preg_match('/\b(copper|aluminium|aluminum|brass|nylon|pvc|xlpe|lszh|tinned)\b/i', $normalized) === 1
            && !preg_match('/cable|gland|connector|terminal|lug|conduit/i', $normalized)
        ) {
            return ['semanticType' => LegacyTermSemanticType::PRODUCT_ATTRIBUTE, 'reason' => 'material_or_construction_attribute', 'confidence' => 'high'];
        }
        if (preg_match('/fire resistant|termite|screened|armour|armor|flexible$|flexibility|temperature|core count|cores?\b|conductor/i', $decoded) === 1
            && !preg_match('/cable|conduit|gland/i', $decoded)
        ) {
            return ['semanticType' => LegacyTermSemanticType::PRODUCT_ATTRIBUTE, 'reason' => 'construction_or_performance_attribute', 'confidence' => 'medium'];
        }
        if (preg_match('/\b(tool|crimper|die|dies|lockout|tagout|hardware & fittings|preparation)\b/i', $decoded) === 1) {
            return ['semanticType' => LegacyTermSemanticType::CATALOGUE_NAVIGATION, 'reason' => 'tools_or_merchandising_navigation', 'confidence' => 'high'];
        }
        if (preg_match('/\b(ship loader|crane|mining|solar|marine|welding|industrial|construction)\b/i', $normalized) === 1
            && !preg_match('/cable|plug|socket|gland|connector|terminal/i', $normalized)
        ) {
            return ['semanticType' => LegacyTermSemanticType::APPLICATION, 'reason' => 'application_or_market_segment', 'confidence' => 'medium'];
        }
        if (in_array($normalized, ['power', 'accessories and wiring', 'other tools', 'quick connect'], true)) {
            return ['semanticType' => LegacyTermSemanticType::UNKNOWN, 'reason' => 'overbroad_or_ambiguous_label', 'confidence' => 'low'];
        }
        if (preg_match('/\b(gland|terminal|ferrule|bootlace|connector|conduit|cleat|link|lug|heat shrink|plug.?socket lead)\b/i', $decoded) === 1) {
            return ['semanticType' => LegacyTermSemanticType::PRODUCT_CATEGORY, 'reason' => 'stable_accessory_or_termination_product_type', 'confidence' => 'medium'];
        }
        if (preg_match('/flexible cable|instrumentation|control & instrumentation|power and control|single core|vfd/i', $decoded) === 1) {
            return ['semanticType' => LegacyTermSemanticType::PRODUCT_CATEGORY, 'reason' => 'stable_cable_product_type_candidate', 'confidence' => 'medium'];
        }

        return ['semanticType' => LegacyTermSemanticType::PRODUCT_CATEGORY, 'reason' => 'retained_product_category_candidate', 'confidence' => 'low'];
    }

    /**
     * @param array<string, array<string, mixed>> $families
     * @return array{code: string, name: string, family: string, reason: string, confidence: string, evidenceScore: string}|null
     */
    private function proposeCreate(
        string $decoded,
        string $normalized,
        int $usage,
        ?string $family,
        array $families,
    ): ?array {
        if ($usage < 3 || $family === null || !isset($families[$family])) {
            return null;
        }

        // Prefer reuse of existing CABLE categories (FLEX-POWER, etc.) over create.
        // Only propose ACCESSORIES product types with strong usage + clear semantics.
        $map = [
            'glands' => ['GLANDS', 'Glands', 'ACCESSORIES', 'accessory_gland_product_type'],
            'conduit glands' => ['CONDUIT-GLANDS', 'Conduit Glands', 'ACCESSORIES', 'accessory_conduit_gland_product_type'],
            'bootlace ferrule' => ['BOOTLACE-FERRULE', 'Bootlace / Ferrule', 'ACCESSORIES', 'accessory_ferrule_product_type'],
            'terminals' => ['TERMINALS', 'Terminals', 'ACCESSORIES', 'accessory_terminal_product_type'],
            'techno connectors' => ['TECHNO-CONNECTORS', 'Techno Connectors', 'ACCESSORIES', 'accessory_connector_product_type'],
            'flexible conduit' => ['FLEX-CONDUIT', 'Flexible Conduit', 'ACCESSORIES', 'accessory_conduit_product_type'],
            'conduits and fittings' => ['CONDUITS-FITTINGS', 'Conduits & Fittings', 'ACCESSORIES', 'accessory_conduit_fittings_product_type'],
            'links' => ['LINKS', 'Links', 'ACCESSORIES', 'accessory_link_product_type'],
            'plugsocket leads' => ['PLUG-SOCKET-LEADS', 'Plug-Socket Leads', 'ACCESSORIES', 'accessory_lead_product_type'],
            'plug socket leads' => ['PLUG-SOCKET-LEADS', 'Plug-Socket Leads', 'ACCESSORIES', 'accessory_lead_product_type'],
        ];

        if (!isset($map[$normalized])) {
            return null;
        }
        [$code, $name, $fam, $reason] = $map[$normalized];
        if (!isset($families[$fam])) {
            return null;
        }

        return [
            'code' => $code,
            'name' => $name,
            'family' => $fam,
            'reason' => $reason,
            'confidence' => $usage >= 5 ? 'high' : 'medium',
            'evidenceScore' => $usage >= 5 ? 'HIGH' : 'MEDIUM',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $categoriesByName
     * @return array<string, mixed>|null
     */
    private function fuzzyExisting(string $normalized, array $categoriesByName): ?array
    {
        foreach ($categoriesByName as $name => $cat) {
            if ($name === $normalized) {
                return null;
            }
            if (strlen($normalized) >= 4 && (str_contains($name, $normalized) || str_contains($normalized, $name))) {
                return [
                    'status' => 'SUGGESTED',
                    'code' => $cat['code'] ?? null,
                    'name' => $cat['name'] ?? null,
                    'note' => 'Fuzzy suggestion only — cannot auto APPROVED_MAP_EXISTING',
                ];
            }
        }
        // Known semantic near-matches
        $hints = [
            'instrumentation collective' => 'INSTRUMENTATION',
            'instrumentation individual and collective' => 'INSTRUMENTATION',
            'control and instrumentation' => 'INSTRUMENTATION',
            'flexible cables' => 'FLEX-POWER',
            'h7 flexible power' => 'FLEX-POWER',
            'power and control' => 'CTRL-MULTI',
            'vfd' => 'VFD',
        ];
        foreach ($hints as $needle => $code) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return [
                    'status' => 'SUGGESTED',
                    'code' => $code,
                    'name' => null,
                    'note' => 'Semantic suggestion only — operator Map Existing required',
                ];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $duplicateGroups
     * @param list<array<string, mixed>> $resolvedItems
     * @return list<array<string, mixed>>
     */
    private function resolveDuplicates(array $duplicateGroups, array $resolvedItems): array
    {
        $byNorm = [];
        foreach ($resolvedItems as $item) {
            $n = (string) ($item['normalizedName'] ?? '');
            if ($n !== '') {
                $byNorm[$n][] = $item;
            }
        }
        $out = [];
        foreach ($duplicateGroups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $norm = (string) ($group['normalizedName'] ?? '');
            $raws = $group['rawValues'] ?? [];
            $relation = 'UNKNOWN';
            if (is_array($raws) && count(array_unique(array_map('strval', $raws))) === 1) {
                $relation = 'EXACT_DUPLICATE';
            } elseif ($this->hasHtmlEntityVariant($raws)) {
                $relation = 'HTML_ENTITY_VARIANT';
            } elseif (is_array($raws) && count($raws) > 1) {
                $relation = 'DISPLAY_VARIANT';
            }
            $out[] = [
                'normalizedName' => $norm,
                'rawValues' => $raws,
                'termIds' => $group['termIds'] ?? [],
                'semanticType' => $group['semanticType'] ?? null,
                'priorDecision' => $group['decision'] ?? null,
                'relationship' => $relation,
                'resolvedMembers' => $byNorm[$norm] ?? [],
                'reason' => $group['reason'] ?? null,
                'autoMerge' => false,
            ];
        }

        return $out;
    }

    /**
     * @param mixed $raws
     */
    private function hasHtmlEntityVariant(mixed $raws): bool
    {
        if (!is_array($raws)) {
            return false;
        }
        foreach ($raws as $raw) {
            if (is_string($raw) && str_contains($raw, '&')) {
                return true;
            }
        }

        return false;
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
            // Existing target categories are available as parents once mapped/approved.
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
            // PROPOSED_CREATE does not unlock Series approval.
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $seriesItems
     * @param array<string, array<string, mixed>> $seriesExisting
     * @param array<string, array<string, mixed>> $families
     * @param array<string, true> $approvedCategoryCodes
     * @param array<string, mixed> $operatorOverrides
     * @return array<string, mixed>
     */
    private function resolveSeries(
        array $seriesItems,
        array $seriesExisting,
        array $families,
        array $approvedCategoryCodes,
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
        $items = [];
        foreach ($seriesItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = MigrationPayload::string($row['styleCode'] ?? $row['legacyStyleCode'] ?? $row['proposedSeriesCode'] ?? '');
            $style = $this->styleNormalizer->normalize($raw);
            $family = strtoupper(MigrationPayload::string($row['family'] ?? ''));
            $categoryCode = MigrationPayload::stringOrNull($row['categoryCode'] ?? $row['category'] ?? null);
            $title = MigrationPayload::string($row['productTitle'] ?? $row['proposedSeriesName'] ?? '');
            $override = is_array($operatorOverrides['series:' . strtoupper($raw)] ?? null)
                ? $operatorOverrides['series:' . strtoupper($raw)]
                : null;

            $decision = SeriesDecisionState::REVIEW;
            $reason = 'category_unresolved_or_insufficient_series_evidence';
            $confidence = 'low';
            $proposedCode = $style['normalized'];
            $proposedName = $title !== '' ? $title : ($style['normalized'] ?? $raw);
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
                $decision = SeriesDecisionState::APPROVED_MAP_EXISTING;
                $reason = 'exact_series_code_match';
                $confidence = 'high';
                $targetPublicId = MigrationPayload::stringOrNull($seriesExisting[$proposedCode]['publicId'] ?? null);
                $proposedName = MigrationPayload::string($seriesExisting[$proposedCode]['name'] ?? $proposedName);
            } elseif ($family !== '' && !isset($families[$family])) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'family_unknown_on_target';
            } elseif ($categoryCode === null || $categoryCode === '' || !isset($approvedCategoryCodes[strtoupper($categoryCode)])) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'parent_category_not_operator_approved';
            } elseif (($row['identityStatus'] ?? null) === 'SOURCE_ONLY') {
                $decision = SeriesDecisionState::SOURCE_ONLY;
                $reason = 'source_only_identity_insufficient_for_series_create';
            } else {
                // Eligible for proposal only — not APPROVED_CREATE.
                $decision = SeriesDecisionState::PROPOSED_CREATE;
                $reason = 'eligible_pending_operator_approve_create';
                $confidence = 'medium';
            }

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
                'tcMatch' => $row['tcMatch'] ?? $row['tproMatch'] ?? null,
                'variantCount' => (int) ($row['variantCount'] ?? 0),
                'existingTargetSeries' => $row['existingSeriesMatch'] ?? ($proposedCode !== null && isset($seriesExisting[$proposedCode]) ? $proposedCode : null),
                'proposedCode' => $proposedCode,
                'proposedName' => $proposedName,
                'targetSeriesPublicId' => $targetPublicId,
                'decision' => $decision,
                'confidence' => $confidence,
                'evidence' => [
                    'styleIssues' => $style['issues'],
                    'styleStatus' => $style['status'],
                ],
                'reason' => $reason,
                'conflict' => $decision === SeriesDecisionState::CONFLICT,
            ];
        }

        return ['counts' => $counts, 'items' => $items];
    }

    /**
     * @param array<string, array<string, mixed>> $families
     */
    private function suggestFamily(string $decoded, string $semanticType, array $families): ?string
    {
        if ($semanticType !== LegacyTermSemanticType::PRODUCT_CATEGORY && $semanticType !== LegacyTermSemanticType::UNKNOWN) {
            return null;
        }
        $n = strtolower($decoded);
        if (preg_match('/gland|lug|heat.?shrink|ferrule|bootlace|connector|conduit|cleat|terminal|link|lead/i', $n) === 1) {
            return isset($families['ACCESSORIES']) ? 'ACCESSORIES' : null;
        }
        if (preg_match('/\bplug\b|\bsocket\b/i', $n) === 1 && !preg_match('/lead/i', $n)) {
            return isset($families['PLUG']) ? 'PLUG' : null;
        }

        return isset($families['CABLE']) ? 'CABLE' : array_key_first($families);
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
                'usageCount' => $row['usageCount'] ?? $row['usageCnt'] ?? $row['usage'] ?? null,
            ];
        }

        return $out;
    }
}
