<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Application\DTO\BatchAClassificationReport;
use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\ClassificationNameNormalizer;
use TriCab\Core\Product\Migration\Classification\LegacyTermSemanticClassifier;
use TriCab\Core\Product\Migration\Classification\LegacyTermSemanticType;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch A — Categories + Series classification review & dry-run planning.
 * Never mutates Products, Variants, Catalogue, or legacy sources.
 * Target Category/Series create is planned only; apply requires separate approval.
 */
final class PlanBatchAClassificationService
{
    public function __construct(
        private readonly LegacyTermSemanticClassifier $semanticClassifier = new LegacyTermSemanticClassifier(),
        private readonly ClassificationNameNormalizer $nameNormalizer = new ClassificationNameNormalizer(),
        private readonly StyleCodeNormalizer $styleNormalizer = new StyleCodeNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, mixed> $azureSnapshot
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $operatorOverrides keyed by "category:{termId}" or "series:{style}"
     */
    public function dryRun(
        array $phase126,
        array $azureSnapshot,
        array $environment,
        array $operatorOverrides = [],
    ): BatchAClassificationReport {
        $families = $this->indexByCode($azureSnapshot['families'] ?? [], 'code');
        $categories = $this->indexByCode($azureSnapshot['categories'] ?? [], 'code');
        $series = $this->indexByCode($azureSnapshot['series'] ?? [], 'code');
        $categoriesByNormalizedName = $this->indexCategoriesByNormalizedName($categories);

        $legacyTerms = $this->legacyCategoryTerms($phase126);
        $categoryReview = $this->decideCategories(
            $legacyTerms,
            $categories,
            $categoriesByNormalizedName,
            $families,
            $operatorOverrides,
        );
        $seriesReview = $this->decideSeries(
            $phase126['classification']['series'] ?? $phase126['series'] ?? [],
            $series,
            $categoryReview,
            $families,
            $operatorOverrides,
        );
        $productPreview = $this->buildProductPreview($phase126, $categoryReview, $seriesReview, $families);
        $duplicates = $this->buildDuplicateReport($categoryReview['items']);
        $conflicts = array_values(array_merge(
            $categoryReview['conflicts'],
            $seriesReview['conflicts'],
        ));
        $approvedCategories = array_values(array_filter(
            $categoryReview['items'],
            static fn (array $row): bool => in_array(
                $row['decision'],
                [CategoryDecisionState::APPROVED_MAP_EXISTING, CategoryDecisionState::APPROVED_CREATE],
                true,
            ),
        ));
        $approvedSeries = array_values(array_filter(
            $seriesReview['items'],
            static function (array $row) use ($categoryReview): bool {
                if (!in_array($row['decision'], [SeriesDecisionState::APPROVED_MAP_EXISTING, SeriesDecisionState::APPROVED_CREATE], true)) {
                    return false;
                }
                $parent = MigrationPayload::string($row['categoryCode'] ?? '');
                if ($parent === '') {
                    return ($row['decision'] === SeriesDecisionState::APPROVED_MAP_EXISTING);
                }

                return isset($categoryReview['approvedCategoryCodes'][$parent])
                    || ($row['decision'] === SeriesDecisionState::APPROVED_MAP_EXISTING);
            },
        ));

        $dryRunPlan = $this->buildDryRunPlan($approvedCategories, $approvedSeries, $families, $categories, $series);
        $classificationReadyCounts = $this->countBy($productPreview, 'classificationStatus');
        $remainingReasons = $this->groupRemainingReasons($categoryReview['items'], $seriesReview['items'], $productPreview);

        $payload = [
            'phase' => '12.7-batch-a',
            'environment' => $environment,
            'targetInspection' => [
                'families' => array_values($families),
                'categories' => array_values($categories),
                'series' => array_values($series),
                'productCount' => (int) ($azureSnapshot['productCount'] ?? 118),
                'variantCount' => (int) ($azureSnapshot['variantCount'] ?? 4178),
                'catalogueNodeCount' => (int) ($azureSnapshot['catalogueNodeCount'] ?? (
                    is_array($azureSnapshot['catalogueNodes'] ?? null) ? count($azureSnapshot['catalogueNodes']) : 97
                )),
                'source' => MigrationPayload::string($azureSnapshot['source'] ?? 'snapshot'),
            ],
            'legacyInventory' => [
                'termCount' => count($legacyTerms),
                'semanticTypeCounts' => $categoryReview['semanticCounts'],
            ],
            'categories' => [
                'counts' => $categoryReview['counts'],
                'items' => $categoryReview['items'],
                'approvedCreateEvidence' => array_values(array_filter(
                    $categoryReview['items'],
                    static fn (array $r): bool => $r['decision'] === CategoryDecisionState::APPROVED_CREATE,
                )),
                'approvedMapExisting' => array_values(array_filter(
                    $categoryReview['items'],
                    static fn (array $r): bool => $r['decision'] === CategoryDecisionState::APPROVED_MAP_EXISTING,
                )),
            ],
            'duplicates' => $duplicates,
            'series' => [
                'counts' => $seriesReview['counts'],
                'items' => $seriesReview['items'],
            ],
            'conflicts' => $conflicts,
            'productClassificationPreview' => [
                'counts' => $classificationReadyCounts,
                'items' => $productPreview,
            ],
            'approvedSets' => [
                'categories' => $approvedCategories,
                'series' => $approvedSeries,
            ],
            'dryRunTargetPlan' => $dryRunPlan,
            'remainingBlockers' => $remainingReasons,
            'readinessBaseline' => [
                'productsReady' => 24,
                'productsReview' => 205,
                'productsSourceOnly' => 211,
                'productsInvalid' => 8,
                'variantsReady' => 0,
                'variantsReview' => 18975,
                'variantsBlocked' => 20,
                'familiesMapped' => 2,
                'categoriesMapped' => 0,
                'seriesMapped' => 0,
                'fullCatalogueDryRun' => 'NOT READY',
            ],
            'projectedClassificationReady' => (int) ($classificationReadyCounts['CLASSIFICATION_READY'] ?? 0),
            'approvalBoundary' => [
                'stopAfterDryRun' => true,
                'applyStatus' => 'NOT RUN',
                'nextAction' => 'WAIT FOR EXPLICIT BATCH A APPLY APPROVAL',
                'doNotStart' => [
                    'Batch B Specification Masters',
                    'Batch C d/v mappings',
                    'Accessory identity mapping',
                    'Phase 12.8',
                    'Product migration',
                    'Variant migration',
                ],
            ],
            'bulkApprovalPolicy' => [
                'allowed' => true,
                'requiresEvidenceEquivalence' => true,
                'neverBulkApproveAllSeries' => true,
                'mustShow' => ['numberAffected', 'rule', 'sampleRecords', 'conflictsExcluded'],
            ],
            'permissions' => [
                'read' => 'PRODUCT.MIGRATION.MANAGE or PRODUCT.READ (review reads)',
                'decide' => 'PRODUCT.MIGRATION.MANAGE',
                'createMasterData' => 'PRODUCT.LOOKUPS.MANAGE (apply only, after approval)',
            ],
        ];

        return new BatchAClassificationReport(
            mode: 'REVIEW + DRY-RUN',
            applied: false,
            legacyWrites: 0,
            productWrites: 0,
            variantWrites: 0,
            catalogueWrites: 0,
            payload: $payload,
        );
    }

    /**
     * Persistable decision update validation (no DB write here).
     *
     * @param array<string, mixed> $decision
     * @param list<array<string, mixed>> $bulkCandidates
     * @return array{ok: bool, error?: string, affected?: int, excludedConflicts?: int}
     */
    public function validateBulkApproval(string $entityType, array $decision, array $bulkCandidates): array
    {
        $rule = MigrationPayload::string($decision['rule'] ?? '');
        $targetDecision = MigrationPayload::string($decision['decision'] ?? '');
        if ($rule === '' || $targetDecision === '') {
            return ['ok' => false, 'error' => 'bulk_approval_requires_rule_and_decision'];
        }
        if ($entityType === 'series' && count($bulkCandidates) > 50) {
            return ['ok' => false, 'error' => 'refusing_bulk_approve_large_series_set'];
        }

        $family = MigrationPayload::string($decision['family'] ?? '');
        $category = MigrationPayload::string($decision['category'] ?? '');
        $affected = 0;
        $excluded = 0;
        foreach ($bulkCandidates as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['conflict'] ?? false) === true) {
                ++$excluded;
                continue;
            }
            if ($family !== '' && MigrationPayload::string($row['proposedFamily'] ?? $row['family'] ?? '') !== $family) {
                ++$excluded;
                continue;
            }
            if ($category !== '' && MigrationPayload::string($row['categoryCode'] ?? $row['proposedCategoryCode'] ?? '') !== $category) {
                ++$excluded;
                continue;
            }
            if (MigrationPayload::string($row['evidenceRule'] ?? $row['reason'] ?? '') !== $rule
                && MigrationPayload::string($row['semanticType'] ?? '') !== $rule
            ) {
                ++$excluded;
                continue;
            }
            ++$affected;
        }

        if ($affected === 0) {
            return ['ok' => false, 'error' => 'no_evidence_equivalent_records', 'affected' => 0, 'excludedConflicts' => $excluded];
        }

        return ['ok' => true, 'affected' => $affected, 'excludedConflicts' => $excluded];
    }

    /**
     * @param array<string, mixed> $phase126
     * @return list<array<string, mixed>>
     */
    private function legacyCategoryTerms(array $phase126): array
    {
        $terms = $phase126['classification']['categories'] ?? [];
        if (!is_array($terms) || $terms === []) {
            $path = $phase126['categoryMappingsPath'] ?? null;
            if (is_string($path) && is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                $terms = is_array($decoded) ? $decoded : [];
            }
        }

        return array_values(array_filter($terms, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $legacyTerms
     * @param array<string, array<string, mixed>> $categories
     * @param array<string, array<string, mixed>> $categoriesByNormalizedName
     * @param array<string, array<string, mixed>> $families
     * @param array<string, mixed> $operatorOverrides
     * @return array<string, mixed>
     */
    private function decideCategories(
        array $legacyTerms,
        array $categories,
        array $categoriesByNormalizedName,
        array $families,
        array $operatorOverrides,
    ): array {
        $counts = [
            CategoryDecisionState::APPROVED_MAP_EXISTING => 0,
            CategoryDecisionState::APPROVED_CREATE => 0,
            CategoryDecisionState::REVIEW => 0,
            CategoryDecisionState::NOT_A_PRODUCT_CATEGORY => 0,
            CategoryDecisionState::REJECT => 0,
            CategoryDecisionState::CONFLICT => 0,
        ];
        $semanticCounts = [];
        $items = [];
        $conflicts = [];
        $mapClaims = [];

        foreach ($legacyTerms as $term) {
            $semantic = $this->semanticClassifier->classify($term);
            $type = $semantic['semanticType'];
            $semanticCounts[$type] = ($semanticCounts[$type] ?? 0) + 1;
            $termId = (int) ($term['termId'] ?? 0);
            $rawName = MigrationPayload::string($term['name'] ?? '');
            $decoded = MigrationPayload::string($term['nameDecoded'] ?? html_entity_decode($rawName, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $overrideKey = 'category:' . $termId;
            $override = is_array($operatorOverrides[$overrideKey] ?? null) ? $operatorOverrides[$overrideKey] : null;

            $proposedFamily = $this->suggestFamily($decoded, $type, $families);
            $exactNameMatch = $categoriesByNormalizedName[$semantic['normalizedName']] ?? null;
            $exactCodeMatch = null;
            $codeCandidate = $this->proposeCategoryCode($decoded);
            if ($codeCandidate !== null && isset($categories[$codeCandidate])) {
                $exactCodeMatch = $categories[$codeCandidate];
            }

            $decision = CategoryDecisionState::REVIEW;
            $reason = $semantic['reason'];
            $targetCode = null;
            $targetPublicId = null;
            $suggested = null;
            $confidence = $semantic['confidence'];

            if ($type === LegacyTermSemanticType::OBSOLETE) {
                $decision = CategoryDecisionState::REJECT;
                $reason = 'obsolete_taxonomy_term';
            } elseif ($type !== LegacyTermSemanticType::PRODUCT_CATEGORY) {
                $decision = CategoryDecisionState::NOT_A_PRODUCT_CATEGORY;
                $reason = 'semantic_type_' . strtolower($type);
            } elseif ($exactNameMatch !== null) {
                $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
                $targetCode = MigrationPayload::string($exactNameMatch['code'] ?? '');
                $targetPublicId = MigrationPayload::stringOrNull($exactNameMatch['publicId'] ?? null);
                $proposedFamily = MigrationPayload::string($exactNameMatch['familyCode'] ?? $proposedFamily ?? '');
                $reason = 'exact_normalized_name_match_existing_category';
                $confidence = 'high';
            } elseif ($exactCodeMatch !== null && $this->namesAligned($decoded, MigrationPayload::string($exactCodeMatch['name'] ?? ''))) {
                $decision = CategoryDecisionState::APPROVED_MAP_EXISTING;
                $targetCode = MigrationPayload::string($exactCodeMatch['code'] ?? '');
                $targetPublicId = MigrationPayload::stringOrNull($exactCodeMatch['publicId'] ?? null);
                $proposedFamily = MigrationPayload::string($exactCodeMatch['familyCode'] ?? $proposedFamily ?? '');
                $reason = 'exact_code_and_name_aligned_match';
                $confidence = 'high';
            } else {
                $suggested = $this->fuzzySuggest($semantic['normalizedName'], $categoriesByNormalizedName);
                $reason = $suggested !== null
                    ? 'product_category_candidate_with_suggested_match'
                    : 'product_category_candidate_requires_operator_family_and_decision';
                // Never auto APPROVED_CREATE — operator must approve create with evidence.
                $decision = CategoryDecisionState::REVIEW;
            }

            if ($override !== null) {
                $decision = MigrationPayload::string($override['decision'] ?? $decision);
                $reason = MigrationPayload::string($override['reason'] ?? $reason);
                $targetCode = MigrationPayload::stringOrNull($override['targetCategoryCode'] ?? $targetCode);
                $proposedFamily = MigrationPayload::stringOrNull($override['proposedFamily'] ?? $proposedFamily);
                $confidence = MigrationPayload::string($override['confidence'] ?? $confidence);
            }

            if ($decision === CategoryDecisionState::APPROVED_CREATE) {
                if ($proposedFamily === null || $proposedFamily === '' || !isset($families[$proposedFamily])) {
                    $decision = CategoryDecisionState::CONFLICT;
                    $reason = 'approved_create_missing_or_unknown_family';
                    $conflicts[] = [
                        'type' => 'category',
                        'termId' => $termId,
                        'reason' => $reason,
                    ];
                } elseif ($codeCandidate === null) {
                    $decision = CategoryDecisionState::REVIEW;
                    $reason = 'code_collision_or_unstable_code_candidate';
                } elseif (isset($categories[$codeCandidate])) {
                    $decision = CategoryDecisionState::CONFLICT;
                    $reason = 'category_code_collision_no_silent_suffix';
                    $conflicts[] = [
                        'type' => 'category',
                        'termId' => $termId,
                        'code' => $codeCandidate,
                        'reason' => $reason,
                    ];
                }
            }

            if ($decision === CategoryDecisionState::APPROVED_MAP_EXISTING && $targetCode !== null) {
                $mapClaims[$targetCode][] = $termId;
            }

            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
            $items[] = [
                'termId' => $termId,
                'taxonomy' => MigrationPayload::string($term['taxonomy'] ?? 'category'),
                'parentTermId' => (int) ($term['parent'] ?? 0),
                'rawName' => $rawName,
                'decodedName' => $decoded,
                'normalizedName' => $semantic['normalizedName'],
                'slug' => MigrationPayload::string($term['slug'] ?? ''),
                'productUsageCount' => (int) ($term['productUsageCount'] ?? 0),
                'relatedPostTypes' => $term['relatedPostTypes'] ?? $term['postTypes'] ?? null,
                'sampleProducts' => $term['sampleProducts'] ?? [],
                'semanticType' => $type,
                'proposedFamily' => $proposedFamily,
                'proposedCategoryCode' => $codeCandidate,
                'targetCategoryCode' => $targetCode,
                'targetCategoryPublicId' => $targetPublicId,
                'suggestedExistingCategory' => $suggested,
                'decision' => $decision,
                'reason' => $reason,
                'confidence' => $confidence,
                'evidenceRule' => $type,
                'conflict' => $decision === CategoryDecisionState::CONFLICT,
                'operatorOverride' => $override !== null,
            ];
        }

        foreach ($mapClaims as $code => $termIds) {
            if (count($termIds) <= 1) {
                continue;
            }
            // Keep highest-usage claim; demote others to REVIEW as duplicate source terms.
            $ranked = [];
            foreach ($items as $idx => $item) {
                if (($item['targetCategoryCode'] ?? null) === $code
                    && $item['decision'] === CategoryDecisionState::APPROVED_MAP_EXISTING
                ) {
                    $ranked[] = $idx;
                }
            }
            usort($ranked, static function (int $a, int $b) use ($items): int {
                return ($items[$b]['productUsageCount'] <=> $items[$a]['productUsageCount']);
            });
            foreach (array_slice($ranked, 1) as $idx) {
                $counts[CategoryDecisionState::APPROVED_MAP_EXISTING]--;
                $counts[CategoryDecisionState::REVIEW]++;
                $items[$idx]['decision'] = CategoryDecisionState::REVIEW;
                $items[$idx]['reason'] = 'duplicate_legacy_term_same_target_keep_primary_map';
                $items[$idx]['conflict'] = false;
            }
        }

        $approvedCategoryCodes = [];
        foreach ($items as $item) {
            if ($item['decision'] === CategoryDecisionState::APPROVED_MAP_EXISTING && !empty($item['targetCategoryCode'])) {
                $approvedCategoryCodes[$item['targetCategoryCode']] = true;
            }
            if ($item['decision'] === CategoryDecisionState::APPROVED_CREATE && !empty($item['proposedCategoryCode'])) {
                $approvedCategoryCodes[$item['proposedCategoryCode']] = true;
            }
        }

        return [
            'counts' => $counts,
            'semanticCounts' => $semanticCounts,
            'items' => $items,
            'conflicts' => $conflicts,
            'approvedCategoryCodes' => $approvedCategoryCodes,
        ];
    }

    /**
     * @param mixed $seriesRows
     * @param array<string, array<string, mixed>> $seriesExisting
     * @param array<string, mixed> $categoryReview
     * @param array<string, array<string, mixed>> $families
     * @param array<string, mixed> $operatorOverrides
     * @return array<string, mixed>
     */
    private function decideSeries(
        mixed $seriesRows,
        array $seriesExisting,
        array $categoryReview,
        array $families,
        array $operatorOverrides,
    ): array {
        $counts = [
            SeriesDecisionState::APPROVED_MAP_EXISTING => 0,
            SeriesDecisionState::APPROVED_CREATE => 0,
            SeriesDecisionState::REVIEW => 0,
            SeriesDecisionState::INVALID => 0,
            SeriesDecisionState::SOURCE_ONLY => 0,
            SeriesDecisionState::REJECT => 0,
            SeriesDecisionState::CONFLICT => 0,
        ];
        $items = [];
        $conflicts = [];
        $rows = is_array($seriesRows) ? $seriesRows : [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = MigrationPayload::string($row['legacyStyleCode'] ?? $row['proposedSeriesCode'] ?? '');
            $style = $this->styleNormalizer->normalize($raw);
            $family = strtoupper(MigrationPayload::string($row['family'] ?? ''));
            $title = MigrationPayload::string($row['productTitle'] ?? $row['proposedSeriesName'] ?? '');
            $overrideKey = 'series:' . strtoupper($raw);
            $override = is_array($operatorOverrides[$overrideKey] ?? null) ? $operatorOverrides[$overrideKey] : null;

            $decision = SeriesDecisionState::REVIEW;
            $reason = 'series_requires_approved_category_and_valid_identity';
            $categoryCode = MigrationPayload::stringOrNull($row['category'] ?? $row['categoryCode'] ?? null);
            $proposedCode = $style['normalized'];
            $proposedName = $title !== '' ? $title : ($style['normalized'] ?? $raw);
            $targetPublicId = null;

            if ($style['isBlank'] || in_array('blank_style_code', $style['issues'], true)) {
                $decision = SeriesDecisionState::INVALID;
                $reason = 'blank_style_code';
            } elseif ($style['isCommaSeparated']) {
                $decision = SeriesDecisionState::INVALID;
                $reason = 'composite_or_comma_style_quarantined';
            } elseif ($this->isJunkStyle($raw)) {
                $decision = SeriesDecisionState::INVALID;
                $reason = 'malformed_or_junk_style_quarantined';
            } elseif ($style['isNumeric']) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'numeric_tc_cable_style_uncertain_not_auto_series';
            } elseif ($proposedCode !== null && isset($seriesExisting[$proposedCode])) {
                $decision = SeriesDecisionState::APPROVED_MAP_EXISTING;
                $reason = 'exact_series_code_match';
                $targetPublicId = MigrationPayload::stringOrNull($seriesExisting[$proposedCode]['publicId'] ?? null);
                $proposedName = MigrationPayload::string($seriesExisting[$proposedCode]['name'] ?? $proposedName);
            } elseif ($family !== '' && !isset($families[$family])) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'family_unknown_on_target';
            } elseif ($categoryCode === null || $categoryCode === '') {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'category_unresolved_series_cannot_approve_create';
            } elseif (!isset($categoryReview['approvedCategoryCodes'][$categoryCode])) {
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'parent_category_not_approved';
            } else {
                // Eligible path still stays REVIEW unless operator override APPROVED_CREATE.
                $decision = SeriesDecisionState::REVIEW;
                $reason = 'eligible_pending_operator_approve_create';
            }

            if (($row['identityStatus'] ?? null) === 'SOURCE_ONLY') {
                $decision = SeriesDecisionState::SOURCE_ONLY;
                $reason = 'source_only_identity_insufficient_for_series_create';
            }

            if ($override !== null) {
                $decision = MigrationPayload::string($override['decision'] ?? $decision);
                $reason = MigrationPayload::string($override['reason'] ?? $reason);
                $categoryCode = MigrationPayload::stringOrNull($override['categoryCode'] ?? $categoryCode);
                $proposedName = MigrationPayload::string($override['proposedSeriesName'] ?? $proposedName);
            }

            if ($decision === SeriesDecisionState::APPROVED_CREATE) {
                if ($proposedCode === null || $proposedCode === '') {
                    $decision = SeriesDecisionState::INVALID;
                    $reason = 'missing_series_code';
                } elseif (isset($seriesExisting[$proposedCode])) {
                    $decision = SeriesDecisionState::CONFLICT;
                    $reason = 'series_code_collision_no_silent_suffix';
                    $conflicts[] = ['type' => 'series', 'style' => $raw, 'reason' => $reason];
                } elseif ($categoryCode === null || !isset($categoryReview['approvedCategoryCodes'][$categoryCode])) {
                    $decision = SeriesDecisionState::CONFLICT;
                    $reason = 'series_create_requires_approved_category';
                    $conflicts[] = ['type' => 'series', 'style' => $raw, 'reason' => $reason];
                }
            }

            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
            $items[] = [
                'styleCode' => $raw,
                'styleNormalized' => $style['normalized'],
                'styleStatus' => $style['status'],
                'styleIssues' => $style['issues'],
                'productTitle' => $title,
                'family' => $family !== '' ? $family : null,
                'categoryCode' => $categoryCode,
                'variantCount' => (int) ($row['variantCount'] ?? 0),
                'wpProductCount' => (int) ($row['wpProductCount'] ?? $row['productCount'] ?? 0),
                'postType' => $row['postType'] ?? null,
                'tcMatch' => $row['tcMatch'] ?? $row['tproMatch'] ?? null,
                'existingSeriesMatch' => $row['existingSeriesMatch'] ?? ($proposedCode !== null && isset($seriesExisting[$proposedCode]) ? $proposedCode : null),
                'proposedSeriesCode' => $proposedCode,
                'proposedSeriesName' => $proposedName,
                'targetSeriesPublicId' => $targetPublicId,
                'decision' => $decision,
                'reason' => $reason,
                'evidenceRule' => $reason,
                'conflict' => $decision === SeriesDecisionState::CONFLICT,
                'operatorOverride' => $override !== null,
            ];
        }

        return [
            'counts' => $counts,
            'items' => $items,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, mixed> $categoryReview
     * @param array<string, mixed> $seriesReview
     * @param array<string, array<string, mixed>> $families
     * @return list<array<string, mixed>>
     */
    private function buildProductPreview(
        array $phase126,
        array $categoryReview,
        array $seriesReview,
        array $families,
    ): array {
        $identity = $phase126['productIdentity']['items'] ?? $phase126['productIdentity'] ?? [];
        if (!is_array($identity)) {
            $identity = [];
        }
        // Prefer readiness report product list when present.
        $products = $phase126['productReadiness']['items'] ?? null;
        if (is_array($products) && $products !== []) {
            $identity = $products;
        }

        $seriesByStyle = [];
        foreach ($seriesReview['items'] as $s) {
            $key = strtoupper((string) ($s['styleNormalized'] ?? $s['styleCode'] ?? ''));
            if ($key !== '') {
                $seriesByStyle[$key] = $s;
            }
        }
        $categoryByTerm = [];
        foreach ($categoryReview['items'] as $c) {
            $categoryByTerm[(int) $c['termId']] = $c;
        }

        $out = [];
        foreach ($identity as $row) {
            if (!is_array($row)) {
                continue;
            }
            $postId = (int) ($row['legacyPostId'] ?? $row['postId'] ?? $row['id'] ?? 0);
            $title = MigrationPayload::string($row['title'] ?? $row['productTitle'] ?? $row['name'] ?? '');
            $postType = MigrationPayload::string($row['postType'] ?? $row['sourcePostType'] ?? '');
            $style = MigrationPayload::string($row['styleCode'] ?? $row['style_code'] ?? $row['legacyStyleCode'] ?? '');
            $familyCode = null;
            if ($postType === 'cable') {
                $familyCode = isset($families['CABLE']) ? 'CABLE' : null;
            } elseif ($postType === 'accessory') {
                $familyCode = isset($families['ACCESSORIES']) ? 'ACCESSORIES' : null;
            }

            $termIds = $row['termIds'] ?? $row['categoryTermIds'] ?? [];
            $category = null;
            if (is_array($termIds)) {
                foreach ($termIds as $tid) {
                    $cand = $categoryByTerm[(int) $tid] ?? null;
                    if ($cand !== null && in_array($cand['decision'], [
                        CategoryDecisionState::APPROVED_MAP_EXISTING,
                        CategoryDecisionState::APPROVED_CREATE,
                    ], true)) {
                        $category = $cand;
                        break;
                    }
                }
            }

            $styleKey = strtoupper(trim($style));
            $series = $seriesByStyle[$styleKey] ?? null;

            $status = 'CLASSIFICATION_REVIEW';
            $reason = 'classification_chain_incomplete';
            if ($familyCode === null) {
                $status = 'CLASSIFICATION_REVIEW';
                $reason = 'family_unresolved';
            } elseif ($category === null) {
                $status = 'CLASSIFICATION_REVIEW';
                $reason = 'category_unresolved';
            } elseif ($series === null || !in_array($series['decision'], [
                SeriesDecisionState::APPROVED_MAP_EXISTING,
                SeriesDecisionState::APPROVED_CREATE,
            ], true)) {
                if ($series !== null && $series['decision'] === SeriesDecisionState::INVALID) {
                    $status = 'CLASSIFICATION_INVALID';
                    $reason = 'invalid_style';
                } else {
                    $status = 'CLASSIFICATION_REVIEW';
                    $reason = 'series_unresolved';
                }
            } else {
                $status = 'CLASSIFICATION_READY';
                $reason = 'family_category_series_resolved';
            }

            if (($row['status'] ?? $row['identityStatus'] ?? null) === 'INVALID') {
                $status = 'CLASSIFICATION_INVALID';
                $reason = 'product_identity_invalid';
            }

            $out[] = [
                'legacyPostId' => $postId,
                'postType' => $postType,
                'title' => $title,
                'styleCode' => $style,
                'family' => [
                    'code' => $familyCode,
                    'publicId' => $familyCode !== null ? MigrationPayload::stringOrNull($families[$familyCode]['publicId'] ?? null) : null,
                ],
                'category' => [
                    'code' => $category['targetCategoryCode'] ?? $category['proposedCategoryCode'] ?? null,
                    'publicId' => $category['targetCategoryPublicId'] ?? null,
                    'decision' => $category['decision'] ?? null,
                ],
                'series' => [
                    'code' => $series['proposedSeriesCode'] ?? null,
                    'publicId' => $series['targetSeriesPublicId'] ?? null,
                    'decision' => $series['decision'] ?? null,
                ],
                'classificationStatus' => $status,
                'reason' => $reason,
            ];
        }

        // When identity list is empty, emit summary-only placeholder from readiness counts.
        if ($out === []) {
            $counts = $phase126['productIdentity']['counts'] ?? $phase126['productReadiness']['counts'] ?? [];
            if (is_array($counts)) {
                $out[] = [
                    'legacyPostId' => 0,
                    'postType' => null,
                    'title' => 'SUMMARY_ONLY_NO_PRODUCT_ROWS_IN_ARTIFACT',
                    'styleCode' => null,
                    'family' => null,
                    'category' => null,
                    'series' => null,
                    'classificationStatus' => 'CLASSIFICATION_REVIEW',
                    'reason' => 'preview_requires_product_rows_artifact_or_live_source',
                    'baselineCounts' => $counts,
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function buildDuplicateReport(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = (string) ($item['normalizedName'] ?? '');
            if ($key === '') {
                continue;
            }
            $groups[$key][] = $item;
        }
        $out = [];
        foreach ($groups as $key => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $semantic = (string) ($rows[0]['semanticType'] ?? LegacyTermSemanticType::UNKNOWN);
            $decision = $semantic === LegacyTermSemanticType::PRODUCT_CATEGORY
                ? CategoryDecisionState::REVIEW
                : CategoryDecisionState::NOT_A_PRODUCT_CATEGORY;
            $out[] = [
                'normalizedName' => $key,
                'rawValues' => array_values(array_unique(array_map(
                    static fn (array $r): string => (string) ($r['rawName'] ?? ''),
                    $rows,
                ))),
                'termIds' => array_map(static fn (array $r): int => (int) ($r['termId'] ?? 0), $rows),
                'semanticType' => $semantic,
                'decision' => $decision,
                'reason' => $semantic === LegacyTermSemanticType::VOLTAGE
                    ? 'duplicate_voltage_terms_belong_to_specification_or_filters'
                    : 'duplicate_normalized_legacy_terms_require_operator_consolidation',
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $approvedCategories
     * @param list<array<string, mixed>> $approvedSeries
     * @param array<string, array<string, mixed>> $families
     * @param array<string, array<string, mixed>> $categories
     * @param array<string, array<string, mixed>> $series
     * @return array<string, mixed>
     */
    private function buildDryRunPlan(
        array $approvedCategories,
        array $approvedSeries,
        array $families,
        array $categories,
        array $series,
    ): array {
        $catCreate = 0;
        $catMap = 0;
        $serCreate = 0;
        $serMap = 0;
        $mappingInsert = 0;
        $mappingUpdate = 0;
        foreach ($approvedCategories as $row) {
            if ($row['decision'] === CategoryDecisionState::APPROVED_CREATE) {
                ++$catCreate;
            } else {
                ++$catMap;
            }
            ++$mappingInsert;
        }
        foreach ($approvedSeries as $row) {
            if ($row['decision'] === SeriesDecisionState::APPROVED_CREATE) {
                ++$serCreate;
            } else {
                ++$serMap;
            }
            ++$mappingInsert;
        }
        unset($families, $categories, $series);

        return [
            'families' => ['mapExisting' => 0, 'create' => 0],
            'categories' => [
                'mapExisting' => $catMap,
                'create' => $catCreate,
                'skipReview' => true,
                'conflict' => 0,
            ],
            'series' => [
                'mapExisting' => $serMap,
                'create' => $serCreate,
                'skipReview' => true,
                'conflict' => 0,
            ],
            'mappingRows' => [
                'insert' => $mappingInsert,
                'update' => $mappingUpdate,
                'skip' => 0,
            ],
            'products' => ['mutations' => 0],
            'variants' => ['mutations' => 0],
            'catalogue' => ['mutations' => 0],
            'azureProductWrites' => 0,
            'legacyWrites' => 0,
            'note' => 'Dry-run only. Target Category/Series rows are NOT written until explicit Batch A apply approval.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @param list<array<string, mixed>> $series
     * @param list<array<string, mixed>> $products
     * @return list<array{reason: string, count: int}>
     */
    private function groupRemainingReasons(array $categories, array $series, array $products): array
    {
        $bucket = [
            'Category unresolved' => 0,
            'Series unresolved' => 0,
            'invalid style' => 0,
            'composite style' => 0,
            'ambiguous taxonomy' => 0,
            'source-only identity' => 0,
            'accessory identity' => 0,
            'target conflict' => 0,
            'other' => 0,
        ];
        foreach ($categories as $c) {
            if (in_array($c['decision'], [CategoryDecisionState::APPROVED_MAP_EXISTING, CategoryDecisionState::APPROVED_CREATE, CategoryDecisionState::NOT_A_PRODUCT_CATEGORY, CategoryDecisionState::REJECT], true)) {
                continue;
            }
            if ($c['decision'] === CategoryDecisionState::CONFLICT) {
                $bucket['target conflict']++;
            } elseif (($c['semanticType'] ?? '') === LegacyTermSemanticType::UNKNOWN) {
                $bucket['ambiguous taxonomy']++;
            } else {
                $bucket['Category unresolved']++;
            }
        }
        foreach ($series as $s) {
            if (in_array($s['decision'], [SeriesDecisionState::APPROVED_MAP_EXISTING, SeriesDecisionState::APPROVED_CREATE], true)) {
                continue;
            }
            $reason = (string) ($s['reason'] ?? '');
            if ($s['decision'] === SeriesDecisionState::INVALID && str_contains($reason, 'comma')) {
                $bucket['composite style']++;
            } elseif ($s['decision'] === SeriesDecisionState::INVALID) {
                $bucket['invalid style']++;
            } elseif ($s['decision'] === SeriesDecisionState::SOURCE_ONLY) {
                $bucket['source-only identity']++;
            } elseif ($s['decision'] === SeriesDecisionState::CONFLICT) {
                $bucket['target conflict']++;
            } elseif (str_contains($reason, 'category')) {
                $bucket['Category unresolved']++;
            } else {
                $bucket['Series unresolved']++;
            }
        }
        foreach ($products as $p) {
            $r = (string) ($p['reason'] ?? '');
            if ($r === 'accessory_identity' || str_contains($r, 'accessory')) {
                $bucket['accessory identity']++;
            }
        }

        $out = [];
        foreach ($bucket as $reason => $count) {
            if ($count > 0) {
                $out[] = ['reason' => $reason, 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $families
     */
    private function suggestFamily(string $decoded, string $semanticType, array $families): ?string
    {
        if ($semanticType !== LegacyTermSemanticType::PRODUCT_CATEGORY) {
            return null;
        }
        $n = strtolower($decoded);
        if (preg_match('/gland|lug|heat.?shrink|ferrule|bootlace|connector|conduit|cleat|accessori/i', $n) === 1) {
            return isset($families['ACCESSORIES']) ? 'ACCESSORIES' : null;
        }
        if (preg_match('/plug|socket/i', $n) === 1) {
            return isset($families['PLUG']) ? 'PLUG' : null;
        }
        if (isset($families['CABLE'])) {
            return 'CABLE';
        }

        return array_key_first($families);
    }

    private function proposeCategoryCode(string $decoded): ?string
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $decoded) ?? '');
        $code = trim($code, '-');
        if ($code === '' || strlen($code) > 40) {
            return null;
        }

        return $code;
    }

    private function namesAligned(string $a, string $b): bool
    {
        return $this->nameNormalizer->normalize($a) === $this->nameNormalizer->normalize($b);
    }

    /**
     * @param array<string, array<string, mixed>> $byNormalizedName
     * @return array<string, mixed>|null
     */
    private function fuzzySuggest(string $normalized, array $byNormalizedName): ?array
    {
        if ($normalized === '') {
            return null;
        }
        foreach ($byNormalizedName as $name => $cat) {
            if ($name === $normalized) {
                return null;
            }
            if (str_contains($name, $normalized) || str_contains($normalized, $name)) {
                if (strlen($normalized) >= 3 && strlen($name) >= 3) {
                    return [
                        'status' => 'SUGGESTED',
                        'code' => $cat['code'] ?? null,
                        'name' => $cat['name'] ?? null,
                        'note' => 'Fuzzy suggestion only — cannot auto APPROVED_MAP_EXISTING',
                    ];
                }
            }
        }

        return null;
    }

    private function isJunkStyle(string $raw): bool
    {
        $t = trim($raw);
        if (preg_match('/^(B3C|B3M)(\s*,\s*(B3C|B3M))+$/i', $t) === 1) {
            return true;
        }

        return (bool) preg_match('/^(null|undefined|n\/a|na|-)$/i', $t);
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexByCode(mixed $rows, string $codeKey): array
    {
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(MigrationPayload::string($row[$codeKey] ?? $row['Code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'code' => $code,
                'name' => MigrationPayload::string($row['name'] ?? $row['Name'] ?? ''),
                'publicId' => MigrationPayload::stringOrNull($row['publicId'] ?? $row['PublicId'] ?? null),
                'familyCode' => MigrationPayload::stringOrNull($row['familyCode'] ?? $row['FamilyCode'] ?? null),
                'categoryCode' => MigrationPayload::stringOrNull($row['categoryCode'] ?? $row['CategoryCode'] ?? null),
                'displayOrder' => $row['displayOrder'] ?? $row['DisplayOrder'] ?? null,
                'isActive' => $row['isActive'] ?? $row['IsActive'] ?? true,
                'deleted' => $row['deleted'] ?? false,
                'usageCount' => $row['usageCount'] ?? $row['usageCnt'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $categories
     * @return array<string, array<string, mixed>>
     */
    private function indexCategoriesByNormalizedName(array $categories): array
    {
        $out = [];
        foreach ($categories as $cat) {
            $norm = $this->nameNormalizer->normalize((string) ($cat['name'] ?? ''));
            if ($norm !== '') {
                $out[$norm] = $cat;
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
        foreach ($rows as $row) {
            $k = (string) ($row[$key] ?? 'UNKNOWN');
            $out[$k] = ($out[$k] ?? 0) + 1;
        }

        return $out;
    }
}
