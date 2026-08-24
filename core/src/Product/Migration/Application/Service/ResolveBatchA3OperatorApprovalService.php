<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 Batch A.3 — apply explicit operator decisions, rebuild approved sets,
 * READY product subset, and idempotent target apply plan. Never executes --apply.
 */
final class ResolveBatchA3OperatorApprovalService
{
    public function __construct(
        private readonly StyleCodeNormalizer $styleNormalizer = new StyleCodeNormalizer(),
        private readonly ResolveBatchA2ClassificationService $a2 = new ResolveBatchA2ClassificationService(),
    ) {
    }

    /**
     * @param array<string, mixed> $a2Result
     * @param array<string, mixed> $operatorDecisions
     * @param array<string, mixed> $azureSnapshot
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    public function build(
        array $a2Result,
        array $operatorDecisions,
        array $azureSnapshot,
        array $environment,
    ): array {
        $categoryItems = is_array($a2Result['categories']['items'] ?? null)
            ? $a2Result['categories']['items']
            : (is_array($a2Result['items'] ?? null) ? $a2Result['items'] : []);
        $seriesItems = is_array($a2Result['series']['items'] ?? null) ? $a2Result['series']['items'] : [];

        $technoFamily = $this->resolveTechnoFamily($operatorDecisions, $categoryItems);
        $categoryOverrides = $this->buildCategoryOverrides($operatorDecisions, $technoFamily, $categoryItems);
        $seriesOverrides = is_array($operatorDecisions['series'] ?? null) ? $operatorDecisions['series'] : [];

        // Re-run A.2 evidence base with operator overrides (preserves MAP_EXISTING; applies APPROVED_CREATE).
        $resolved = $this->a2->resolve(
            $categoryItems,
            $seriesItems,
            $azureSnapshot,
            $environment,
            array_merge($categoryOverrides, $this->seriesOverrideKeys($seriesOverrides)),
            is_array($a2Result['overlaps']['pairs'] ?? null) || isset($a2Result['wpEvidence'])
                ? ($a2Result['wpEvidence'] ?? [
                    'overlaps' => $a2Result['overlaps']['pairs'] ?? [],
                    'productsByTerm' => $this->rebuildProductsByTerm($categoryItems),
                ])
                : [
                    'overlaps' => $a2Result['overlaps']['pairs'] ?? [],
                    'productsByTerm' => $this->rebuildProductsByTerm($categoryItems),
                ],
        );

        // Apply series operator APPROVED_CREATE / MAP after A.2 eligibility.
        $resolved['series'] = $this->applySeriesOperatorApprovals(
            $resolved['series'],
            $seriesOverrides,
            $this->approvedCategoryCodes($resolved['categories']['items'] ?? [], $azureSnapshot),
        );

        $approvedCategories = array_values(array_filter(
            $resolved['categories']['items'] ?? [],
            static fn (array $r): bool => in_array(
                $r['decision'] ?? '',
                [CategoryDecisionState::APPROVED_MAP_EXISTING, CategoryDecisionState::APPROVED_CREATE],
                true,
            ),
        ));
        $approvedSeries = array_values(array_filter(
            $resolved['series']['items'] ?? [],
            static fn (array $r): bool => in_array(
                $r['decision'] ?? '',
                [SeriesDecisionState::APPROVED_MAP_EXISTING, SeriesDecisionState::APPROVED_CREATE],
                true,
            ),
        ));

        $validation = $this->validateApprovedSets($approvedCategories, $approvedSeries, $azureSnapshot);
        $readyPackage = $this->buildReadySubset($resolved['series']['items'] ?? [], $approvedCategories, $approvedSeries);
        $targetPlan = $this->buildTargetPlan($approvedCategories, $approvedSeries, $azureSnapshot, $validation);
        $counts = $this->countDecisions($resolved['categories']['items'] ?? [], $resolved['series']['items'] ?? []);

        return [
            'phase' => '12.7-batch-a.3',
            'mode' => 'OPERATOR_APPROVAL + FINAL_APPLY_PACKAGE',
            'applied' => false,
            'legacyWrites' => 0,
            'productWrites' => 0,
            'variantWrites' => 0,
            'catalogueWrites' => 0,
            'environment' => $environment,
            'targetLiveValidation' => MigrationPayload::string($azureSnapshot['targetLiveValidation'] ?? 'UNAVAILABLE'),
            'targetInspection' => $resolved['targetInspection'] ?? [],
            'technoFamilyDecision' => $technoFamily,
            'categories' => [
                'counts' => $counts['categories'],
                'items' => $resolved['categories']['items'] ?? [],
                'vfd' => $resolved['categories']['vfd'] ?? null,
                'accessoryDecisions' => $resolved['categories']['accessoryDecisions'] ?? null,
            ],
            'series' => [
                'counts' => $counts['series'],
                'items' => $resolved['series']['items'] ?? [],
                'cardinality' => $resolved['series']['cardinality'] ?? null,
            ],
            'approvedSets' => [
                'categories' => $approvedCategories,
                'series' => $approvedSeries,
            ],
            'approvedSetValidation' => $validation,
            'readiness' => $readyPackage['readiness'],
            'classificationReadyProducts' => $readyPackage['ready'],
            'classificationReviewProducts' => $readyPackage['review'],
            'classificationInvalidProducts' => $readyPackage['invalid'],
            'variantImpact' => $readyPackage['variantImpact'],
            'targetApplyPlan' => $targetPlan,
            'approvalBoundary' => [
                'applyStatus' => 'NOT RUN',
                'batchAApplyGuard' => 'BATCH_A_APPLY_NOT_APPROVED',
                'finalApplyPackage' => ($azureSnapshot['targetLiveValidation'] ?? '') === 'AVAILABLE'
                    ? ($validation['ok'] ? 'READY_FOR_EXPLICIT_APPLY_APPROVAL' : 'BLOCKED_APPROVED_SET_VALIDATION')
                    : 'BLOCKED_TARGET_UNAVAILABLE',
                'recommendation' => $this->recommendation($validation, $counts, $readyPackage, $azureSnapshot),
                'nextAction' => 'WAIT FOR EXPLICIT OPERATOR APPROVAL',
                'fullCatalogueMigration' => 'NOT READY',
            ],
        ];
    }

    /**
     * Safe bulk Series approval validator (no Approve All).
     *
     * @param array<string, mixed> $decision
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    public function validateBulkSeriesApproval(array $decision, array $candidates): array
    {
        $rule = MigrationPayload::string($decision['rule'] ?? '');
        $target = MigrationPayload::string($decision['decision'] ?? '');
        $family = MigrationPayload::string($decision['family'] ?? '');
        $category = MigrationPayload::string($decision['category'] ?? '');
        if ($rule === '' || $target === '') {
            return ['ok' => false, 'error' => 'bulk_requires_rule_and_decision'];
        }
        if ($target === SeriesDecisionState::APPROVED_CREATE && ($family === '' || $category === '')) {
            return ['ok' => false, 'error' => 'bulk_approve_create_requires_family_and_category'];
        }
        if (count($candidates) > 100) {
            return ['ok' => false, 'error' => 'refusing_bulk_approve_all_pattern'];
        }

        $affected = [];
        $excluded = [];
        foreach ($candidates as $row) {
            if (!is_array($row)) {
                continue;
            }
            $style = $this->styleNormalizer->normalize((string) ($row['styleCode'] ?? ''));
            if ($style['isBlank'] || $style['isCommaSeparated'] || $style['isNumeric']) {
                $excluded[] = ['styleCode' => $row['styleCode'] ?? null, 'reason' => 'invalid_or_composite_or_numeric_style'];
                continue;
            }
            if (($row['conflict'] ?? false) === true) {
                $excluded[] = ['styleCode' => $row['styleCode'] ?? null, 'reason' => 'conflict'];
                continue;
            }
            if ($family !== '' && MigrationPayload::string($row['family'] ?? '') !== $family) {
                $excluded[] = ['styleCode' => $row['styleCode'] ?? null, 'reason' => 'family_mismatch'];
                continue;
            }
            if ($category !== '' && MigrationPayload::string($row['category'] ?? $row['categoryCode'] ?? '') !== $category) {
                $excluded[] = ['styleCode' => $row['styleCode'] ?? null, 'reason' => 'category_mismatch'];
                continue;
            }
            if (MigrationPayload::string($row['evidenceRule'] ?? $row['reason'] ?? '') !== $rule
                && MigrationPayload::string($row['decision'] ?? '') !== $rule
            ) {
                $excluded[] = ['styleCode' => $row['styleCode'] ?? null, 'reason' => 'rule_mismatch'];
                continue;
            }
            $affected[] = $row;
        }

        if ($affected === []) {
            return ['ok' => false, 'error' => 'no_evidence_equivalent_records', 'excluded' => $excluded];
        }

        return [
            'ok' => true,
            'rule' => $rule,
            'decision' => $target,
            'affected' => count($affected),
            'excludedConflicts' => count($excluded),
            'samples' => array_slice($affected, 0, 5),
            'excluded' => array_slice($excluded, 0, 20),
        ];
    }

    /**
     * @param array<string, mixed> $operatorDecisions
     * @param list<array<string, mixed>> $categoryItems
     * @return array{family: string, reason: string, confidence: string}
     */
    private function resolveTechnoFamily(array $operatorDecisions, array $categoryItems): array
    {
        if (!empty($operatorDecisions['technoFamily'])) {
            $fam = strtoupper(MigrationPayload::string($operatorDecisions['technoFamily']));
            if (in_array($fam, ['PLUG', 'ACCESSORIES', 'REVIEW'], true)) {
                return ['family' => $fam, 'reason' => 'operator_explicit_techno_family', 'confidence' => 'high'];
            }
        }

        $products = [];
        foreach ($categoryItems as $item) {
            if (($item['normalizedName'] ?? '') === 'techno connectors'
                || ($item['proposedCategoryCode'] ?? '') === 'TECHNO-CONNECTORS'
            ) {
                $products = $item['sampleProducts'] ?? $item['evidence']['wpProducts'] ?? [];
                break;
            }
        }
        $plugish = 0;
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $blob = strtolower(($p['title'] ?? '') . ' ' . ($p['style_code'] ?? ''));
            if (preg_match('/plug|socket|junction|\bj9[0-7]\b/', $blob) === 1) {
                ++$plugish;
            }
        }
        if ($plugish >= 4) {
            return [
                'family' => 'PLUG',
                'reason' => 'techno_products_are_plug_socket_junction_styles_under_plugs_sockets_parent_not_ind_plug_duplicate',
                'confidence' => 'high',
            ];
        }

        return ['family' => 'REVIEW', 'reason' => 'insufficient_techno_family_evidence', 'confidence' => 'low'];
    }

    /**
     * @param array<string, mixed> $operatorDecisions
     * @param array{family: string, reason: string, confidence: string} $technoFamily
     * @param list<array<string, mixed>> $categoryItems
     * @return array<string, mixed>
     */
    private function buildCategoryOverrides(array $operatorDecisions, array $technoFamily, array $categoryItems): array
    {
        $overrides = [];
        $approvals = is_array($operatorDecisions['categories'] ?? null) ? $operatorDecisions['categories'] : [];

        // Default Batch A.3 operator package: approve unique PROPOSED creates when decisions file says so.
        foreach ($approvals as $key => $decision) {
            if (!is_array($decision)) {
                continue;
            }
            $termKey = is_string($key) && str_starts_with($key, 'category:') ? $key : 'category:' . $key;
            if (($decision['proposedCategoryCode'] ?? '') === 'TECHNO-CONNECTORS') {
                if ($technoFamily['family'] === 'REVIEW') {
                    $decision['decision'] = CategoryDecisionState::REVIEW;
                    $decision['reason'] = 'techno_family_unresolved_blocks_approve_create';
                } else {
                    $decision['proposedFamily'] = $technoFamily['family'];
                }
            }
            $overrides[$termKey] = $decision;
        }

        // If operator decisions use codes, expand to all matching source terms (e.g. both GLANDS terms).
        $byCode = is_array($operatorDecisions['approveCreateCodes'] ?? null)
            ? $operatorDecisions['approveCreateCodes']
            : [];
        foreach ($byCode as $code => $meta) {
            $code = strtoupper((string) $code);
            $meta = is_array($meta) ? $meta : [];
            foreach ($categoryItems as $item) {
                $itemCode = strtoupper((string) ($item['proposedCategoryCode'] ?? ''));
                if ($itemCode !== $code) {
                    continue;
                }
                $termId = (int) ($item['sourceTermId'] ?? $item['termId'] ?? 0);
                $family = strtoupper(MigrationPayload::string($meta['family'] ?? $item['proposedFamily'] ?? 'ACCESSORIES'));
                if ($code === 'TECHNO-CONNECTORS') {
                    if ($technoFamily['family'] === 'REVIEW') {
                        $overrides['category:' . $termId] = [
                            'decision' => CategoryDecisionState::REVIEW,
                            'reason' => 'techno_family_unresolved_blocks_approve_create',
                        ];
                        continue;
                    }
                    $family = $technoFamily['family'];
                }
                $overrides['category:' . $termId] = [
                    'decision' => CategoryDecisionState::APPROVED_CREATE,
                    'proposedCategoryCode' => $code,
                    'proposedCategoryName' => MigrationPayload::string($meta['name'] ?? $item['proposedCategoryName'] ?? $item['decodedName'] ?? $code),
                    'proposedFamily' => $family,
                    'reason' => MigrationPayload::string($meta['reason'] ?? 'operator_approved_create_batch_a3'),
                    'confidence' => MigrationPayload::string($meta['confidence'] ?? 'high'),
                ];
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $seriesOverrides keyed by style or series:STYLE
     * @return array<string, mixed>
     */
    private function seriesOverrideKeys(array $seriesOverrides): array
    {
        $out = [];
        foreach ($seriesOverrides as $key => $decision) {
            if (!is_array($decision)) {
                continue;
            }
            $k = is_string($key) && str_starts_with(strtoupper($key), 'SERIES:')
                ? $key
                : 'series:' . strtoupper((string) $key);
            $out[$k] = $decision;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $seriesBlock
     * @param array<string, mixed> $seriesOverrides
     * @param array<string, true> $approvedCategoryCodes
     * @return array<string, mixed>
     */
    private function applySeriesOperatorApprovals(
        array $seriesBlock,
        array $seriesOverrides,
        array $approvedCategoryCodes,
    ): array {
        $items = is_array($seriesBlock['items'] ?? null) ? $seriesBlock['items'] : [];
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

        $approveAllEligible = !empty($seriesOverrides['__approveAllEligibleProposed']);
        unset($seriesOverrides['__approveAllEligibleProposed']);

        foreach ($items as &$row) {
            $style = strtoupper((string) ($row['styleCode'] ?? ''));
            $override = $seriesOverrides['series:' . $style] ?? $seriesOverrides[$style] ?? null;
            $decision = (string) ($row['decision'] ?? SeriesDecisionState::REVIEW);
            $reason = (string) ($row['reason'] ?? '');
            $category = strtoupper((string) ($row['category'] ?? $row['categoryCode'] ?? ''));

            if ($approveAllEligible && $decision === SeriesDecisionState::PROPOSED_CREATE) {
                $override = [
                    'decision' => SeriesDecisionState::APPROVED_CREATE,
                    'reason' => 'operator_bulk_eligible_proposed_create_batch_a3',
                ];
            }

            if (is_array($override)) {
                $want = MigrationPayload::string($override['decision'] ?? $decision);
                if ($want === SeriesDecisionState::APPROVED_CREATE) {
                    if ($category === '' || !isset($approvedCategoryCodes[$category])) {
                        $decision = SeriesDecisionState::CONFLICT;
                        $reason = 'series_create_requires_approved_category';
                    } else {
                        $styleInfo = $this->styleNormalizer->normalize((string) ($row['styleCode'] ?? ''));
                        if ($styleInfo['isCommaSeparated'] || $styleInfo['isBlank'] || $styleInfo['isNumeric']) {
                            $decision = SeriesDecisionState::CONFLICT;
                            $reason = 'series_approve_blocked_invalid_style';
                        } else {
                            $decision = SeriesDecisionState::APPROVED_CREATE;
                            $reason = MigrationPayload::string($override['reason'] ?? 'operator_approved_create_batch_a3');
                            $row['confidence'] = 'high';
                        }
                    }
                } elseif ($want === SeriesDecisionState::APPROVED_MAP_EXISTING) {
                    $decision = SeriesDecisionState::APPROVED_MAP_EXISTING;
                    $reason = MigrationPayload::string($override['reason'] ?? 'operator_map_existing_series');
                } else {
                    $decision = $want;
                    $reason = MigrationPayload::string($override['reason'] ?? $reason);
                }
            }

            $row['decision'] = $decision;
            $row['reason'] = $reason;
            $row['conflict'] = $decision === SeriesDecisionState::CONFLICT;
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
        }
        unset($row);

        $seriesBlock['items'] = $items;
        $seriesBlock['counts'] = $counts;

        return $seriesBlock;
    }

    /**
     * @param list<array<string, mixed>> $categoryItems
     * @param array<string, mixed> $azureSnapshot
     * @return array<string, true>
     */
    private function approvedCategoryCodes(array $categoryItems, array $azureSnapshot): array
    {
        $out = [];
        foreach ($azureSnapshot['categories'] ?? [] as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $code = strtoupper((string) ($cat['code'] ?? ''));
            if ($code !== '') {
                $out[$code] = true;
            }
        }
        foreach ($categoryItems as $item) {
            $d = $item['decision'] ?? '';
            if ($d === CategoryDecisionState::APPROVED_MAP_EXISTING && !empty($item['targetCategoryCode'])) {
                $out[strtoupper((string) $item['targetCategoryCode'])] = true;
            }
            if ($d === CategoryDecisionState::APPROVED_CREATE && !empty($item['proposedCategoryCode'])) {
                $out[strtoupper((string) $item['proposedCategoryCode'])] = true;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $approvedCategories
     * @param list<array<string, mixed>> $approvedSeries
     * @param array<string, mixed> $azureSnapshot
     * @return array<string, mixed>
     */
    private function validateApprovedSets(array $approvedCategories, array $approvedSeries, array $azureSnapshot): array
    {
        $errors = [];
        $createCodes = [];
        foreach ($approvedCategories as $row) {
            if (($row['decision'] ?? '') !== CategoryDecisionState::APPROVED_CREATE) {
                continue;
            }
            $code = strtoupper((string) ($row['proposedCategoryCode'] ?? ''));
            $family = strtoupper((string) ($row['proposedFamily'] ?? ''));
            if ($code === '') {
                $errors[] = 'approved_create_missing_code';
            }
            if (!in_array($family, ['CABLE', 'PLUG', 'ACCESSORIES'], true)) {
                $errors[] = 'family_ambiguous:' . $code;
            }
            if (isset($createCodes[$code]) && $createCodes[$code] !== $family) {
                $errors[] = 'duplicate_target_code_family_conflict:' . $code;
            }
            $createCodes[$code] = $family;
            foreach ($azureSnapshot['categories'] ?? [] as $existing) {
                if (!is_array($existing)) {
                    continue;
                }
                if (strtoupper((string) ($existing['code'] ?? '')) === $code) {
                    $errors[] = 'category_code_collision_existing:' . $code;
                }
                if (($existing['isActive'] ?? $existing['active'] ?? true) == false
                    && strtoupper((string) ($row['targetCategoryCode'] ?? '')) === strtoupper((string) ($existing['code'] ?? ''))
                ) {
                    $errors[] = 'inactive_target_category:' . ($existing['code'] ?? '');
                }
            }
        }

        $seriesCodes = [];
        foreach ($approvedSeries as $row) {
            if (($row['decision'] ?? '') !== SeriesDecisionState::APPROVED_CREATE) {
                continue;
            }
            $code = strtoupper((string) ($row['proposedCode'] ?? $row['styleCode'] ?? ''));
            if ($code === '') {
                $errors[] = 'series_approved_create_missing_code';
            }
            if (isset($seriesCodes[$code])) {
                $errors[] = 'series_code_collision:' . $code;
            }
            $seriesCodes[$code] = true;
            foreach ($azureSnapshot['series'] ?? [] as $existing) {
                if (!is_array($existing)) {
                    continue;
                }
                if (strtoupper((string) ($existing['code'] ?? '')) === $code) {
                    $errors[] = 'series_code_collision_existing:' . $code;
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param list<array<string, mixed>> $seriesItems
     * @param list<array<string, mixed>> $approvedCategories
     * @param list<array<string, mixed>> $approvedSeries
     * @return array<string, mixed>
     */
    private function buildReadySubset(array $seriesItems, array $approvedCategories, array $approvedSeries): array
    {
        $approvedSeriesByStyle = [];
        foreach ($approvedSeries as $s) {
            $style = strtoupper((string) ($s['styleCode'] ?? $s['proposedCode'] ?? ''));
            if ($style !== '') {
                $approvedSeriesByStyle[$style] = $s;
            }
        }
        $approvedCatCodes = [];
        foreach ($approvedCategories as $c) {
            if (($c['decision'] ?? '') === CategoryDecisionState::APPROVED_MAP_EXISTING) {
                $approvedCatCodes[strtoupper((string) ($c['targetCategoryCode'] ?? ''))] = true;
            }
            if (($c['decision'] ?? '') === CategoryDecisionState::APPROVED_CREATE) {
                $approvedCatCodes[strtoupper((string) ($c['proposedCategoryCode'] ?? ''))] = true;
            }
        }

        $ready = [];
        $review = [];
        $invalid = [];
        $categoryReady = 0;
        $seriesReady = 0;
        $classificationReady = 0;
        $fullReady = 0;
        $fullBlocked = 0;
        $variantReady = 0;
        $blockers = [];

        foreach ($seriesItems as $row) {
            $style = (string) ($row['styleCode'] ?? '');
            $styleInfo = $this->styleNormalizer->normalize($style);
            $family = strtoupper((string) ($row['family'] ?? ''));
            $category = strtoupper((string) ($row['category'] ?? $row['categoryCode'] ?? ''));
            $postType = strtolower((string) ($row['postType'] ?? ''));
            $variants = (int) ($row['variantCount'] ?? 0);
            $title = (string) ($row['productTitle'] ?? '');
            $decision = (string) ($row['decision'] ?? '');

            $catOk = $category !== '' && isset($approvedCatCodes[$category]);
            $seriesOk = isset($approvedSeriesByStyle[strtoupper($style)]);
            if ($catOk) {
                ++$categoryReady;
            }
            if ($seriesOk) {
                ++$seriesReady;
            }

            $status = 'CLASSIFICATION_REVIEW';
            $bucket = 'SERIES_UNRESOLVED';
            if ($styleInfo['isCommaSeparated']) {
                $status = 'CLASSIFICATION_INVALID';
                $bucket = 'COMPOSITE_STYLE';
            } elseif ($styleInfo['isBlank']) {
                $status = 'CLASSIFICATION_INVALID';
                $bucket = 'INVALID_STYLE';
            } elseif ($decision === SeriesDecisionState::CONFLICT) {
                $status = 'CLASSIFICATION_INVALID';
                $bucket = 'TARGET_CONFLICT';
            } elseif (!$catOk) {
                $bucket = 'CATEGORY_UNRESOLVED';
            } elseif (!$seriesOk) {
                $bucket = $decision === SeriesDecisionState::SOURCE_ONLY ? 'SOURCE_ONLY' : 'SERIES_UNRESOLVED';
            } else {
                $status = 'CLASSIFICATION_READY';
                $bucket = null;
                ++$classificationReady;
            }

            $fullMigrationReady = false;
            if ($status === 'CLASSIFICATION_READY') {
                // Accessories with unresolved identity remain classification-ready but not full migration ready.
                if ($family === 'ACCESSORIES' || $postType === 'accessory') {
                    $identity = (string) ($row['tcMatch'] ?? $row['identityStatus'] ?? '');
                    if ($identity === '' || $identity === 'SOURCE_ONLY' || $identity === 'ACCESSORY_UNRESOLVED') {
                        $fullMigrationReady = false;
                        ++$fullBlocked;
                        $blockers['ACCESSORY_IDENTITY'] = ($blockers['ACCESSORY_IDENTITY'] ?? 0) + 1;
                    } else {
                        $fullMigrationReady = true;
                        ++$fullReady;
                    }
                } else {
                    $fullMigrationReady = true;
                    ++$fullReady;
                }
            } else {
                ++$fullBlocked;
                if ($bucket !== null) {
                    $blockers[$bucket] = ($blockers[$bucket] ?? 0) + 1;
                }
            }

            $record = [
                'legacyPostId' => $row['postId'] ?? null,
                'postType' => $postType !== '' ? $postType : ($family === 'ACCESSORIES' ? 'accessory' : 'cable'),
                'productTitle' => $title,
                'style_code' => $style,
                'family' => $family !== '' ? $family : null,
                'category' => $category !== '' ? $category : null,
                'series' => $row['proposedCode'] ?? $style,
                'tcIdentityStatus' => $row['tcMatch'] ?? $row['identityStatus'] ?? null,
                'variantCount' => $variants,
                'classificationStatus' => $status,
                'fullProductMigrationReady' => $fullMigrationReady,
                'blockingReason' => $bucket,
                'evidence' => [
                    'seriesDecision' => $decision,
                    'categoryApproved' => $catOk,
                    'seriesApproved' => $seriesOk,
                ],
            ];

            if ($status === 'CLASSIFICATION_READY') {
                $ready[] = $record;
                $variantReady += $variants;
            } elseif ($status === 'CLASSIFICATION_INVALID') {
                $invalid[] = $record;
            } else {
                $review[] = $record;
            }
        }

        return [
            'ready' => $ready,
            'review' => $review,
            'invalid' => $invalid,
            'readiness' => [
                'CATEGORY_READY' => $categoryReady,
                'SERIES_READY' => $seriesReady,
                'CLASSIFICATION_READY' => $classificationReady,
                'CLASSIFICATION_REVIEW' => count($review),
                'CLASSIFICATION_INVALID' => count($invalid),
                'FULL_PRODUCT_MIGRATION_READY' => $fullReady,
                'FULL_PRODUCT_MIGRATION_BLOCKED' => $fullBlocked,
                'mainBlockers' => $blockers,
            ],
            'variantImpact' => [
                'classificationReadyProducts' => count($ready),
                'associatedTproExtVariants' => $variantReady,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $approvedCategories
     * @param list<array<string, mixed>> $approvedSeries
     * @param array<string, mixed> $azureSnapshot
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function buildTargetPlan(
        array $approvedCategories,
        array $approvedSeries,
        array $azureSnapshot,
        array $validation,
    ): array {
        $mapExistingCats = 0;
        $createCats = 0;
        $createCatRows = [];
        $seenCreate = [];
        foreach ($approvedCategories as $row) {
            if (($row['decision'] ?? '') === CategoryDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapExistingCats;
            }
            if (($row['decision'] ?? '') === CategoryDecisionState::APPROVED_CREATE) {
                $code = strtoupper((string) ($row['proposedCategoryCode'] ?? ''));
                if ($code !== '' && !isset($seenCreate[$code])) {
                    $seenCreate[$code] = true;
                    ++$createCats;
                    $createCatRows[] = [
                        'code' => $code,
                        'name' => $row['proposedCategoryName'] ?? $code,
                        'family' => $row['proposedFamily'] ?? null,
                        'productCount' => (int) ($row['postCount'] ?? 0),
                    ];
                }
            }
        }

        $mapExistingSeries = 0;
        $createSeries = 0;
        foreach ($approvedSeries as $row) {
            if (($row['decision'] ?? '') === SeriesDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapExistingSeries;
            }
            if (($row['decision'] ?? '') === SeriesDecisionState::APPROVED_CREATE) {
                ++$createSeries;
            }
        }

        $mappingInsert = count($approvedCategories) + count($approvedSeries);

        return [
            'order' => [
                '1. Validate Families',
                '2. Validate existing target Categories',
                '3. Insert APPROVED_CREATE Categories',
                '4. Resolve new Category IDs',
                '5. Insert Category mapping rows',
                '6. Validate existing target Series',
                '7. Insert APPROVED_CREATE Series',
                '8. Resolve Series IDs',
                '9. Insert Series mapping rows',
                '10. Verify mappings',
                '11. Recalculate readiness',
            ],
            'categories' => [
                'mapExisting' => $mapExistingCats,
                'create' => $createCats,
                'createRows' => $createCatRows,
            ],
            'series' => [
                'mapExisting' => $mapExistingSeries,
                'create' => $createSeries,
            ],
            'mappings' => [
                'categoryInsert' => count($approvedCategories),
                'seriesInsert' => count($approvedSeries),
                'insert' => $mappingInsert,
                'update' => 0,
            ],
            'mutations' => [
                'products' => 0,
                'variants' => 0,
                'catalogue' => 0,
                'specifications' => 0,
                'pricing' => 0,
                'media' => 0,
                'documents' => 0,
                'legacy' => 0,
            ],
            'idempotency' => [
                'secondRunCategoryCreates' => 0,
                'secondRunSeriesCreates' => 0,
                'secondRunMappingInserts' => 0,
                'note' => 'Stable keys; second identical apply projects zero creates/inserts when rows already exist.',
            ],
            'validationOk' => $validation['ok'],
            'validationErrors' => $validation['errors'],
            'productAssignment' => 'NOT IN SCOPE — Products.FamilyId/CategoryId/SeriesId unchanged by Batch A apply',
        ];
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @param list<array<string, mixed>> $series
     * @return array{categories: array<string, int>, series: array<string, int>}
     */
    private function countDecisions(array $categories, array $series): array
    {
        $cat = [
            CategoryDecisionState::APPROVED_MAP_EXISTING => 0,
            CategoryDecisionState::APPROVED_CREATE => 0,
            CategoryDecisionState::PROPOSED_CREATE => 0,
            CategoryDecisionState::REVIEW => 0,
            CategoryDecisionState::NOT_A_PRODUCT_CATEGORY => 0,
            CategoryDecisionState::REJECT => 0,
            CategoryDecisionState::CONFLICT => 0,
        ];
        foreach ($categories as $row) {
            $d = (string) ($row['decision'] ?? CategoryDecisionState::REVIEW);
            $cat[$d] = ($cat[$d] ?? 0) + 1;
        }
        $ser = [
            SeriesDecisionState::APPROVED_MAP_EXISTING => 0,
            SeriesDecisionState::APPROVED_CREATE => 0,
            SeriesDecisionState::PROPOSED_CREATE => 0,
            SeriesDecisionState::REVIEW => 0,
            SeriesDecisionState::INVALID => 0,
            SeriesDecisionState::SOURCE_ONLY => 0,
            SeriesDecisionState::REJECT => 0,
            SeriesDecisionState::CONFLICT => 0,
        ];
        foreach ($series as $row) {
            $d = (string) ($row['decision'] ?? SeriesDecisionState::REVIEW);
            $ser[$d] = ($ser[$d] ?? 0) + 1;
        }

        return ['categories' => $cat, 'series' => $ser];
    }

    /**
     * @param list<array<string, mixed>> $categoryItems
     * @return array<int, list<array<string, mixed>>>
     */
    private function rebuildProductsByTerm(array $categoryItems): array
    {
        $out = [];
        foreach ($categoryItems as $item) {
            $tid = (int) ($item['sourceTermId'] ?? $item['termId'] ?? 0);
            $products = $item['sampleProducts'] ?? $item['evidence']['wpProducts'] ?? [];
            if ($tid > 0 && is_array($products) && $products !== []) {
                $out[$tid] = array_values(array_filter($products, 'is_array'));
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $validation
     * @param array{categories: array<string, int>, series: array<string, int>} $counts
     * @param array<string, mixed> $readyPackage
     * @param array<string, mixed> $azureSnapshot
     */
    private function recommendation(array $validation, array $counts, array $readyPackage, array $azureSnapshot): string
    {
        if (($azureSnapshot['targetLiveValidation'] ?? '') !== 'AVAILABLE') {
            return 'CONTINUE REVIEW';
        }
        if (!$validation['ok']) {
            return 'CONTINUE REVIEW';
        }
        $ready = (int) ($readyPackage['readiness']['CLASSIFICATION_READY'] ?? 0);
        $creates = (int) ($counts['categories'][CategoryDecisionState::APPROVED_CREATE] ?? 0)
            + (int) ($counts['series'][SeriesDecisionState::APPROVED_CREATE] ?? 0);
        $maps = (int) ($counts['categories'][CategoryDecisionState::APPROVED_MAP_EXISTING] ?? 0);

        if ($maps > 0 && ($creates > 0 || $ready > 0)) {
            return 'APPROVE BATCH A TARGET APPLY';
        }

        return 'CONTINUE REVIEW';
    }
}
