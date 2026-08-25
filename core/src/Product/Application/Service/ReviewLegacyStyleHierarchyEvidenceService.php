<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\StyleDiscoveryProposal;
use TriCab\Core\Product\Application\DTO\StyleHierarchyEvidenceRow;
use TriCab\Core\Product\Application\DTO\StyleInventoryItem;
use TriCab\Core\Product\Application\DTO\StyleMembershipEvidence;
use TriCab\Core\Product\Contract\LegacyStyleOccurrenceSource;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Service\StyleHierarchyCycleGuard;

/**
 * Read-only evidence review of prefix hierarchy proposals.
 * Never writes ParentStyleId, assignments, specs, media, WordPress, or tc MySQL.
 */
final class ReviewLegacyStyleHierarchyEvidenceService
{
    private const SMOKE_CODES = ['BVA', 'BVC', 'BVM', 'BVP', 'BVA-X'];
    private const SIZE_SPEC_FIELDS = [
        'od', 'weight', 'cores', 'conductor_size', 'nc', 'xa', 'ct', 'size', 'length',
        'nominal_od', 'approx_od',
    ];
    private const VARIANT_TOKENS = [
        'flexible', 'armoured', 'armored', 'unscreened', 'screened', 'screen',
        'xlpe', 'pvc', 'rubber', 'instrumentation', 'control', 'power', 'data',
        'fibre', 'fiber', 'ethernet', 'mining', 'trailing', 'swa', 'steel',
        'switchboard', 'marine', 'nylon', 'lszh',
    ];

    public function __construct(
        private readonly DiscoverLegacyStyleHierarchyService $discovery,
        private readonly DiscoverLegacyStyleInventoryService $inventory,
        private readonly ProductStyleRepository $styles,
        private readonly LegacyStyleOccurrenceSource $source,
        private readonly StyleHierarchyCycleGuard $cycleGuard = new StyleHierarchyCycleGuard(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function review(array $extraStyleCodes = []): array
    {
        $preview = $this->discovery->preview($extraStyleCodes);
        $inventory = $this->inventory->discover();
        $itemsByCode = [];
        foreach ($inventory->items as $item) {
            if ($item->normalizedStyleCode !== null) {
                $itemsByCode[strtoupper($item->normalizedStyleCode)] = $item;
            }
        }
        /** @var list<StyleDiscoveryProposal> $proposals */
        $proposals = $preview['proposals'];
        $codes = [];
        foreach ($proposals as $proposal) {
            $codes[$proposal->parentStyleCode] = true;
            $codes[$proposal->childStyleCode] = true;
        }
        $membership = $this->source->inspectMembership(array_keys($codes));

        $rows = [];
        foreach ($proposals as $proposal) {
            $rows[] = $this->reviewProposal(
                $proposal,
                $membership[$proposal->parentStyleCode] ?? $this->emptyMembership($proposal->parentStyleCode),
                $membership[$proposal->childStyleCode] ?? $this->emptyMembership($proposal->childStyleCode),
                $itemsByCode[$proposal->parentStyleCode] ?? null,
                $itemsByCode[$proposal->childStyleCode] ?? null,
            );
        }

        $this->applyGroupAdjustments($rows);
        $this->demoteSelectedCycles($rows);
        $selected = $this->selectApprovals($rows);
        $cycleConflicts = $this->countSelectedCycles($selected, $rows);

        $decisionCounts = [
            'APPROVE_CANDIDATE' => 0,
            'REVIEW' => 0,
            'REJECT' => 0,
            'EXCLUDED_REVIEW' => 0,
            'ALTERNATIVE_PARENT' => 0,
            'ALREADY_PERSISTED' => 0,
        ];
        $confidenceCounts = ['VERY_HIGH' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
        $ambiguous = 0;
        $existingParentConflicts = 0;
        foreach ($rows as $row) {
            $decisionCounts[$row->decision] = ($decisionCounts[$row->decision] ?? 0) + 1;
            $confidenceCounts[$row->reviewedConfidence] = ($confidenceCounts[$row->reviewedConfidence] ?? 0) + 1;
            if (in_array('ambiguous parent', array_map('strtolower', $row->warnings), true) || $row->decision === 'ALTERNATIVE_PARENT') {
                ++$ambiguous;
            }
            if ($row->existingParentState === 'DIFFERENT_PARENT_CONFLICT') {
                ++$existingParentConflicts;
            }
        }

        return [
            'writeGuard' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'filesystemWrites' => 0,
                'parentStyleIdWrites' => 0,
                'productAssignmentWrites' => 0,
                'specificationWrites' => 0,
                'mediaWrites' => 0,
                'blobWrites' => 0,
                'forbiddenWriteAttempts' => 0,
                'persisted' => false,
                'relationshipsApplied' => 0,
            ],
            'sourceReads' => [
                'wordpressReads' => $this->source->wordpressReads(),
                'tcReads' => $this->source->tcReads(),
                'filesystemReads' => $this->source->filesystemReads(),
            ],
            'originalCounters' => $preview['counters'] ?? [],
            'rows' => $rows,
            'selected' => $selected,
            'groups' => $this->groupSummaries($rows),
            'membership' => $membership,
            'preview' => $preview,
            'inventory' => $inventory->toArray(),
            'counters' => [
                'originalHigh' => (int) (($preview['counters']['highConfidence'] ?? 0)),
                'originalMedium' => (int) (($preview['counters']['mediumConfidence'] ?? 0)),
                'originalReview' => (int) (($preview['counters']['review'] ?? 0)),
                'relationshipsInvestigated' => count($rows),
                'approveCandidate' => $decisionCounts['APPROVE_CANDIDATE'],
                'review' => $decisionCounts['REVIEW'],
                'reject' => $decisionCounts['REJECT'],
                'excludedReview' => $decisionCounts['EXCLUDED_REVIEW'],
                'alternativeParent' => $decisionCounts['ALTERNATIVE_PARENT'],
                'alreadyPersisted' => $decisionCounts['ALREADY_PERSISTED'],
                'veryHigh' => $confidenceCounts['VERY_HIGH'],
                'high' => $confidenceCounts['HIGH'],
                'medium' => $confidenceCounts['MEDIUM'],
                'low' => $confidenceCounts['LOW'],
                'ambiguous' => $ambiguous + (int) (($preview['counters']['ambiguousParentCases'] ?? 0)),
                'cycleConflicts' => $cycleConflicts,
                'existingParentConflicts' => $existingParentConflicts,
                'selectedRelationships' => count($selected),
                'relationshipsApplied' => 0,
            ],
        ];
    }

    /**
     * @param list<StyleHierarchyEvidenceRow> $rows
     */
    private function reviewProposal(
        StyleDiscoveryProposal $proposal,
        StyleMembershipEvidence $parentMem,
        StyleMembershipEvidence $childMem,
        ?StyleInventoryItem $parentItem,
        ?StyleInventoryItem $childItem,
    ): StyleHierarchyEvidenceRow {
        $parent = $this->styles->findByStyleCode($proposal->parentStyleCode, false);
        $child = $this->styles->findByStyleCode($proposal->childStyleCode, false);
        $warnings = $proposal->warnings;
        $existingParentState = 'NO_PARENT';
        if ($proposal->applicationState === 'ALREADY_PERSISTED') {
            $existingParentState = 'SAME_PARENT_ALREADY';
        } elseif ($proposal->applicationState === 'CONFLICTS_WITH_PERSISTED' || ($child !== null && $child->parentStyleCode !== null && $child->parentStyleCode !== $proposal->parentStyleCode)) {
            $existingParentState = 'DIFFERENT_PARENT_CONFLICT';
        }

        $parentCount = max($parentMem->productCount(), $parentItem?->productCount ?? 0, $proposal->productCount > 0 && $proposal->childStyleCode === $proposal->parentStyleCode ? $proposal->productCount : 0);
        $childCount = max($childMem->productCount(), $childItem?->productCount ?? 0, $proposal->productCount);

        $numeric = $this->isNumericCode($proposal->parentStyleCode) || $this->isNumericCode($proposal->childStyleCode);
        $cableStyle = $proposal->parentStyleCode === 'CABLE_STYLE' || $proposal->childStyleCode === 'CABLE_STYLE';
        $smoke = in_array($proposal->parentStyleCode, self::SMOKE_CODES, true)
            || in_array($proposal->childStyleCode, self::SMOKE_CODES, true);

        $codeEvidence = 'supporting prefix: ' . $proposal->parentStyleCode . ' is a prefix of ' . $proposal->childStyleCode;
        $classification = $this->classificationEvidence($parentMem, $childMem, $parentItem, $childItem);
        $description = $this->descriptionEvidence($proposal->parentStyleCode, $parentMem, $childMem);
        $specification = $this->specificationEvidence($parentMem, $childMem);
        $media = $this->mediaEvidence($parentMem, $childMem);

        $conflict = null;
        if ($classification['conflict']) {
            $conflict = 'CLASSIFICATION_CONFLICT';
        }
        if ($existingParentState === 'DIFFERENT_PARENT_CONFLICT') {
            $conflict = 'EXISTING_PARENT_CONFLICT';
        }

        $alternative = null;
        if (count($proposal->prefixCandidates) > 1) {
            $alternative = $proposal->prefixCandidates[array_key_last($proposal->prefixCandidates) - 1] ?? null;
            if ($alternative === $proposal->parentStyleCode) {
                $alternative = $proposal->prefixCandidates[0] ?? null;
            }
        }

        $decision = 'REVIEW';
        $reviewed = 'MEDIUM';
        $reason = 'Prefix is evidence only; additional legacy evidence is incomplete.';
        $applyEligible = $parent !== null && $child !== null && $existingParentState === 'NO_PARENT';

        if ($numeric) {
            $decision = 'EXCLUDED_REVIEW';
            $reviewed = 'LOW';
            $applyEligible = false;
            $reason = 'Numeric cable_style relationships are excluded. Numeric parents were not imported and must not be created.';
        } elseif ($cableStyle) {
            $decision = 'REVIEW';
            $reviewed = 'LOW';
            $applyEligible = false;
            $reason = 'CABLE_STYLE is a suspicious field/header-like value. Created as VALID inventory, but not approved as a hierarchy child of CA.';
            $warnings[] = 'Suspicious placeholder-like StyleCode CABLE_STYLE.';
        } elseif ($smoke && $proposal->applicationState === 'ALREADY_PERSISTED') {
            $decision = 'ALREADY_PERSISTED';
            $reviewed = 'LOW';
            $applyEligible = false;
            $reason = 'Persisted smoke/target-only relationship. Isolated from real legacy hierarchy approval.';
        } elseif ($smoke) {
            $decision = 'REVIEW';
            $reviewed = 'LOW';
            $applyEligible = false;
            $reason = 'Smoke/target-only style is not evidence for real hierarchy.';
        } elseif ($proposal->applicationState === 'ALREADY_PERSISTED') {
            $decision = 'ALREADY_PERSISTED';
            $reviewed = 'LOW';
            $applyEligible = false;
            $reason = 'Parent is already stored. Not a new write.';
        } elseif ($parent === null || $child === null) {
            $decision = 'REVIEW';
            $reviewed = 'LOW';
            $applyEligible = false;
            $reason = 'Parent or child is missing from [product].[ProductStyles]. NOT_APPLY_ELIGIBLE.';
        } elseif ($existingParentState === 'DIFFERENT_PARENT_CONFLICT') {
            $decision = 'REVIEW';
            $reviewed = 'LOW';
            $applyEligible = false;
            $reason = 'Child already has a different ParentStyleId. Re-parenting needs separate approval.';
        } elseif ($proposal->ambiguousParent) {
            $decision = 'ALTERNATIVE_PARENT';
            $reviewed = 'MEDIUM';
            $applyEligible = false;
            $reason = 'Multiple prefix parents exist. Operator must choose nested vs sibling.';
        } else {
            $score = 2; // prefix
            if ($parentCount > 0 && $childCount > 0) {
                $score += 2;
            }
            if ($classification['support']) {
                $score += 2;
            }
            if ($classification['conflict']) {
                $score -= 3;
            }
            if ($description['support']) {
                $score += 2;
            }
            if ($specification['support']) {
                $score += 1;
            }
            if ($media['kind'] === 'COMMON_MEDIA_EVIDENCE') {
                $score += 1;
            }
            if ($parentCount === 0 && $childCount === 0) {
                $score -= 1;
            }

            if ($classification['conflict'] && !$description['support']) {
                $decision = 'REJECT';
                $reviewed = 'LOW';
                $applyEligible = false;
                $reason = 'Classification sets do not overlap and there is no supporting description evidence.';
            } elseif ($score >= 6 && $parentCount > 0 && $childCount > 0 && !$classification['conflict']) {
                $decision = 'APPROVE_CANDIDATE';
                $reviewed = $score >= 8 ? 'VERY_HIGH' : 'HIGH';
                $reason = 'Prefix plus product membership and supporting classification/description evidence.';
            } elseif ($score >= 5 && $parentCount > 0 && $childCount > 0 && !$classification['conflict'] && $this->looksLikeVariantSuffix($proposal->parentStyleCode, $proposal->childStyleCode)) {
                $decision = 'APPROVE_CANDIDATE';
                $reviewed = 'HIGH';
                $reason = 'Prefix plus product membership and a short variant suffix.';
            } else {
                $decision = 'REVIEW';
                $reviewed = $score >= 4 ? 'MEDIUM' : 'LOW';
                $reason = 'Plausible prefix but insufficient or mixed legacy evidence for auto-selection.';
                $applyEligible = false;
            }
        }

        $siblings = $this->siblingCodesInTitles($proposal->parentStyleCode, $proposal->childStyleCode, $parentMem);
        if ($decision === 'APPROVE_CANDIDATE' && $siblings !== []) {
            $decision = 'REVIEW';
            $reviewed = 'MEDIUM';
            $applyEligible = false;
            $reason = 'Related codes in parent titles look like siblings rather than a parent/child tree: ' . implode(', ', $siblings) . '.';
            $warnings[] = 'Possible sibling styles: ' . implode(', ', $siblings);
        }

        if ($child !== null && $parent !== null && $applyEligible) {
            $ancestorIds = array_map(static fn (array $a): string => $a['publicId'], $parent->ancestors);
            $ancestorIds = array_merge([$parent->publicId], $ancestorIds);
            try {
                $this->cycleGuard->assertCanSetParent($child->publicId, $parent->publicId, $ancestorIds);
            } catch (\Throwable $exception) {
                $decision = 'REVIEW';
                $reviewed = 'LOW';
                $applyEligible = false;
                $reason = 'Cycle guard would reject this parent: ' . $exception->getMessage();
            }
        }

        return new StyleHierarchyEvidenceRow(
            parentStyleCode: $proposal->parentStyleCode,
            childStyleCode: $proposal->childStyleCode,
            originalConfidence: $proposal->confidence,
            reviewedConfidence: $reviewed,
            decision: $decision,
            existingParentState: $existingParentState,
            applyEligible: $applyEligible && $decision === 'APPROVE_CANDIDATE',
            parentProductCount: $parentCount,
            childProductCount: $childCount,
            codeEvidence: $codeEvidence,
            classificationEvidence: $classification['label'],
            descriptionEvidence: $description['label'],
            specificationEvidence: $specification['label'],
            mediaEvidence: $media['kind'] . ($media['detail'] !== '' ? ': ' . $media['detail'] : ''),
            conflict: $conflict,
            reason: $reason,
            warnings: array_values(array_unique($warnings)),
            prefixCandidates: $proposal->prefixCandidates,
            alternativeParentStyleCode: $alternative,
            parentPublicId: $parent?->publicId,
            childPublicId: $child?->publicId,
            applicationState: $proposal->applicationState,
            selected: false,
        );
    }

    /**
     * @param list<StyleHierarchyEvidenceRow> $rows
     */
    private function applyGroupAdjustments(array &$rows): void
    {
        $byParent = [];
        foreach ($rows as $i => $row) {
            $byParent[$row->parentStyleCode][] = $i;
        }
        if (!isset($byParent['PS'])) {
            return;
        }
        foreach ($byParent['PS'] as $i) {
            $row = $rows[$i];
            if (in_array($row->childStyleCode, ['PS3C', 'PS6', 'PSXA', 'PSKH1C', 'PSKH2A', 'PSKH3E'], true)
                && $row->decision === 'APPROVE_CANDIDATE') {
                $rows[$i] = $this->withDecision(
                    $row,
                    'REVIEW',
                    'MEDIUM',
                    false,
                    'PS1A–PS1D may form a coherent subgroup; ' . $row->childStyleCode . ' is an outlier until operator confirms the whole PS family.',
                );
            }
        }
    }

    /**
     * @param list<StyleHierarchyEvidenceRow> $rows
     * @return list<StyleHierarchyEvidenceRow>
     */
    private function selectApprovals(array &$rows): array
    {
        $selected = [];
        foreach ($rows as $i => $row) {
            $ok = $row->decision === 'APPROVE_CANDIDATE'
                && $row->applyEligible
                && $row->parentPublicId !== null
                && $row->childPublicId !== null
                && $row->existingParentState === 'NO_PARENT';
            $rows[$i] = new StyleHierarchyEvidenceRow(
                parentStyleCode: $row->parentStyleCode,
                childStyleCode: $row->childStyleCode,
                originalConfidence: $row->originalConfidence,
                reviewedConfidence: $row->reviewedConfidence,
                decision: $row->decision,
                existingParentState: $row->existingParentState,
                applyEligible: $ok,
                parentProductCount: $row->parentProductCount,
                childProductCount: $row->childProductCount,
                codeEvidence: $row->codeEvidence,
                classificationEvidence: $row->classificationEvidence,
                descriptionEvidence: $row->descriptionEvidence,
                specificationEvidence: $row->specificationEvidence,
                mediaEvidence: $row->mediaEvidence,
                conflict: $row->conflict,
                reason: $row->reason,
                warnings: $row->warnings,
                prefixCandidates: $row->prefixCandidates,
                alternativeParentStyleCode: $row->alternativeParentStyleCode,
                parentPublicId: $row->parentPublicId,
                childPublicId: $row->childPublicId,
                applicationState: $row->applicationState,
                selected: $ok,
            );
            if ($ok) {
                $selected[] = $rows[$i];
            }
        }

        return $selected;
    }

    /**
     * Combine persisted parents with APPROVE_CANDIDATE rows and demote any cycle.
     *
     * @param list<StyleHierarchyEvidenceRow> $rows
     */
    private function demoteSelectedCycles(array &$rows): void
    {
        $parentByChild = [];
        foreach ($this->styles->listAll(false) as $style) {
            if ($style->parentStyleCode !== null) {
                $parentByChild[$style->styleCode] = $style->parentStyleCode;
            }
        }
        foreach ($rows as $row) {
            if ($row->decision === 'APPROVE_CANDIDATE' && $row->applyEligible) {
                $parentByChild[$row->childStyleCode] = $row->parentStyleCode;
            }
        }
        foreach ($rows as $i => $row) {
            if ($row->decision !== 'APPROVE_CANDIDATE') {
                continue;
            }
            $seen = [$row->childStyleCode => true];
            $current = $parentByChild[$row->childStyleCode] ?? $row->parentStyleCode;
            $cycled = false;
            while (is_string($current) && $current !== '') {
                if (isset($seen[$current])) {
                    $cycled = true;
                    break;
                }
                $seen[$current] = true;
                $current = $parentByChild[$current] ?? null;
            }
            if ($cycled) {
                $rows[$i] = $this->withDecision(
                    $row,
                    'REVIEW',
                    'LOW',
                    false,
                    'Selected set plus persisted parents would create a cycle. Removed from approval set.',
                );
            }
        }
    }

    /**
     * @param list<StyleHierarchyEvidenceRow> $selected
     * @param list<StyleHierarchyEvidenceRow> $rows
     */
    private function countSelectedCycles(array $selected, array $rows): int
    {
        $parentByChild = [];
        foreach ($this->styles->listAll(false) as $style) {
            if ($style->parentStyleCode !== null) {
                $parentByChild[$style->styleCode] = $style->parentStyleCode;
            }
        }
        foreach ($selected as $row) {
            $parentByChild[$row->childStyleCode] = $row->parentStyleCode;
        }
        $cycles = 0;
        foreach ($selected as $row) {
            $seen = [$row->childStyleCode => true];
            $current = $row->parentStyleCode;
            while (is_string($current) && $current !== '') {
                if (isset($seen[$current])) {
                    ++$cycles;
                    break;
                }
                $seen[$current] = true;
                $current = $parentByChild[$current] ?? null;
            }
        }
        unset($rows);

        return $cycles;
    }

    /**
     * @param list<StyleHierarchyEvidenceRow> $rows
     * @return list<array<string, mixed>>
     */
    private function groupSummaries(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[$row->parentStyleCode][] = $row;
        }
        $out = [];
        foreach ($byParent as $parent => $children) {
            if (count($children) < 2) {
                continue;
            }
            $approved = [];
            $outliers = [];
            foreach ($children as $child) {
                if ($child->decision === 'APPROVE_CANDIDATE') {
                    $approved[] = $child->childStyleCode;
                } else {
                    $outliers[] = $child->childStyleCode;
                }
            }
            $out[] = [
                'parent' => $parent,
                'proposedChildren' => array_map(static fn (StyleHierarchyEvidenceRow $r): string => $r->childStyleCode, $children),
                'approvedChildren' => $approved,
                'outlierChildren' => $outliers,
                'possibleSubgrouping' => $parent === 'PS' ? ['PS1A', 'PS1B', 'PS1C', 'PS1D'] : [],
            ];
        }

        return $out;
    }

    /**
     * @return array{support: bool, conflict: bool, label: string}
     */
    private function classificationEvidence(
        StyleMembershipEvidence $parent,
        StyleMembershipEvidence $child,
        ?StyleInventoryItem $parentItem,
        ?StyleInventoryItem $childItem,
    ): array {
        $parentTerms = $parent->terms;
        $childTerms = $child->terms;
        if ($parentItem?->familyEvidence) {
            $parentTerms[] = $parentItem->familyEvidence;
        }
        if ($childItem?->familyEvidence) {
            $childTerms[] = $childItem->familyEvidence;
        }
        $parentTerms = $this->normalizeTerms($parentTerms);
        $childTerms = $this->normalizeTerms($childTerms);
        $parentTypes = array_map('strtolower', $parent->postTypes);
        $childTypes = array_map('strtolower', $child->postTypes);
        $typesOverlap = array_intersect($parentTypes, $childTypes) !== [];
        $typesDisjoint = $parentTypes !== [] && $childTypes !== [] && !$typesOverlap;
        if ($typesDisjoint) {
            return [
                'support' => false,
                'conflict' => true,
                'label' => 'CLASSIFICATION_CONFLICT: WordPress post types differ (' . implode(',', $parent->postTypes) . ' vs ' . implode(',', $child->postTypes) . ')',
            ];
        }
        if ($parentTerms === [] || $childTerms === []) {
            if ($typesOverlap) {
                return ['support' => true, 'conflict' => false, 'label' => 'supporting: same WordPress post type ' . implode(',', $parent->postTypes)];
            }

            return ['support' => false, 'conflict' => false, 'label' => 'NO_EVIDENCE: no comparable Family/Category/Series terms'];
        }
        $overlap = array_values(array_intersect($parentTerms, $childTerms));
        if ($overlap !== []) {
            return ['support' => true, 'conflict' => false, 'label' => 'supporting: shared terms ' . implode(', ', array_slice($overlap, 0, 4))];
        }

        return ['support' => false, 'conflict' => true, 'label' => 'CLASSIFICATION_CONFLICT: no shared terms'];
    }

    /**
     * @return array{support: bool, label: string}
     */
    private function descriptionEvidence(string $parentCode, StyleMembershipEvidence $parent, StyleMembershipEvidence $child): array
    {
        $parentText = strtolower(implode(' ', array_merge($parent->sampleTitles, $parent->descriptionSnippets)));
        $childText = strtolower(implode(' ', array_merge($child->sampleTitles, $child->descriptionSnippets)));
        if (trim($parentText) === '' && trim($childText) === '') {
            return ['support' => false, 'label' => 'NO_EVIDENCE: no titles/descriptions'];
        }
        $childTitleBlob = strtoupper(implode(' ', $child->sampleTitles));
        $childHasParentCode = $childTitleBlob !== ''
            && preg_match('/(?<![A-Z0-9])' . preg_quote($parentCode, '/') . '(?![A-Z0-9])/', $childTitleBlob) === 1;
        $shared = [];
        foreach (self::VARIANT_TOKENS as $token) {
            if (str_contains($parentText, $token) && str_contains($childText, $token)) {
                $shared[] = $token;
            }
        }
        if ($childHasParentCode || $shared !== []) {
            $bits = [];
            if ($childHasParentCode) {
                $bits[] = 'child titles include parent code';
            }
            if ($shared !== []) {
                $bits[] = 'shared construction tokens ' . implode(', ', array_slice($shared, 0, 4));
            }

            return ['support' => true, 'label' => 'supporting: ' . implode('; ', $bits)];
        }
        if ($parent->sampleTitles !== [] && $child->sampleTitles !== []) {
            return ['support' => false, 'label' => 'weak: titles present but no shared construction token'];
        }

        return ['support' => false, 'label' => 'NO_EVIDENCE'];
    }

    /**
     * @return array{support: bool, label: string}
     */
    private function specificationEvidence(StyleMembershipEvidence $parent, StyleMembershipEvidence $child): array
    {
        $parentFields = $this->nonSizeFields($parent->specificationValues);
        $childFields = $this->nonSizeFields($child->specificationValues);
        if ($parentFields === [] && $childFields === []) {
            return ['support' => false, 'label' => 'NO_EVIDENCE: no legacy spec rows (style-owned Azure specs are empty by design)'];
        }
        $same = 0;
        $different = 0;
        $missing = 0;
        $all = array_unique(array_merge(array_keys($parentFields), array_keys($childFields)));
        foreach ($all as $field) {
            $pv = $parentFields[$field] ?? null;
            $cv = $childFields[$field] ?? null;
            if ($pv === null || $cv === null) {
                ++$missing;
                continue;
            }
            if (array_intersect($pv, $cv) !== []) {
                ++$same;
            } else {
                ++$different;
            }
        }

        return [
            'support' => $same > 0 && $same >= $different,
            'label' => sprintf(
                'Definitions compared: %d. Same values/patterns: %d. Different: %d. Missing/inconsistent: %d. Size/variant fields ignored.',
                count($all),
                $same,
                $different,
                $missing,
            ),
        ];
    }

    /**
     * @return array{kind: string, detail: string}
     */
    private function mediaEvidence(StyleMembershipEvidence $parent, StyleMembershipEvidence $child): array
    {
        if ($parent->attachmentFiles === [] && $child->attachmentFiles === []) {
            return ['kind' => 'NO_MEDIA_EVIDENCE', 'detail' => 'no legacy attachments associated'];
        }
        $shared = array_values(array_intersect($parent->attachmentFiles, $child->attachmentFiles));
        if ($shared !== []) {
            return ['kind' => 'COMMON_MEDIA_EVIDENCE', 'detail' => implode(', ', array_slice($shared, 0, 3))];
        }
        if ($parent->attachmentFiles !== [] && $child->attachmentFiles !== []) {
            return ['kind' => 'CONFLICTING_MEDIA_EVIDENCE', 'detail' => 'attachments present on both, no shared filenames'];
        }

        return ['kind' => 'PARTIAL_MEDIA_EVIDENCE', 'detail' => 'attachments on only one side'];
    }

    /**
     * @param array<string, list<string>> $values
     * @return array<string, list<string>>
     */
    private function nonSizeFields(array $values): array
    {
        $out = [];
        foreach ($values as $field => $list) {
            if (in_array(strtolower((string) $field), self::SIZE_SPEC_FIELDS, true)) {
                continue;
            }
            $out[(string) $field] = $list;
        }

        return $out;
    }

    private function looksLikeVariantSuffix(string $parent, string $child): bool
    {
        if (!str_starts_with($child, $parent)) {
            return false;
        }
        $suffix = substr($child, strlen($parent));

        return $suffix !== '' && strlen($suffix) <= 3 && preg_match('/^[0-9A-Z]+$/', $suffix) === 1;
    }

    private function isNumericCode(string $code): bool
    {
        return preg_match('/^\d+$/', $code) === 1;
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private function normalizeTerms(array $terms): array
    {
        $out = [];
        $stop = ['amp', 'and', 'the', 'of', 'for', 'new', 'cable', 'cables', 'wire', 'wires', 'lead', 'leads'];
        foreach ($terms as $term) {
            $decoded = strtolower(trim(html_entity_decode((string) $term, ENT_QUOTES | ENT_HTML5)));
            if ($decoded === '') {
                continue;
            }
            $out[$decoded] = $decoded;
            foreach (preg_split('/[^a-z0-9]+/', $decoded) ?: [] as $part) {
                if (strlen($part) >= 3 && !in_array($part, $stop, true)) {
                    $out[$part] = $part;
                }
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private function siblingCodesInTitles(string $parent, string $child, StyleMembershipEvidence $parentMem): array
    {
        $blob = strtoupper(implode(' ', $parentMem->sampleTitles));
        if ($blob === '') {
            return [];
        }
        preg_match_all('/\b' . preg_quote($parent, '/') . '[A-Z0-9]{1,4}\b/', $blob, $matches);
        $found = [];
        foreach ($matches[0] as $code) {
            if ($code !== $parent && $code !== $child) {
                $found[$code] = $code;
            }
        }

        return array_values($found);
    }

    private function emptyMembership(string $code): StyleMembershipEvidence
    {
        return new StyleMembershipEvidence(
            styleCode: $code,
            wordpressProductCount: 0,
            tcVariantCount: 0,
            sampleTitles: [],
            sampleProducts: [],
            terms: [],
            postTypes: [],
            attachmentFiles: [],
            specificationValues: [],
            descriptionSnippets: [],
            sourcesAvailable: [],
            wordpressAvailable: false,
            tcAvailable: false,
        );
    }

    private function withDecision(
        StyleHierarchyEvidenceRow $row,
        string $decision,
        string $confidence,
        bool $applyEligible,
        string $reason,
    ): StyleHierarchyEvidenceRow {
        return new StyleHierarchyEvidenceRow(
            parentStyleCode: $row->parentStyleCode,
            childStyleCode: $row->childStyleCode,
            originalConfidence: $row->originalConfidence,
            reviewedConfidence: $confidence,
            decision: $decision,
            existingParentState: $row->existingParentState,
            applyEligible: $applyEligible,
            parentProductCount: $row->parentProductCount,
            childProductCount: $row->childProductCount,
            codeEvidence: $row->codeEvidence,
            classificationEvidence: $row->classificationEvidence,
            descriptionEvidence: $row->descriptionEvidence,
            specificationEvidence: $row->specificationEvidence,
            mediaEvidence: $row->mediaEvidence,
            conflict: $row->conflict,
            reason: $reason,
            warnings: $row->warnings,
            prefixCandidates: $row->prefixCandidates,
            alternativeParentStyleCode: $row->alternativeParentStyleCode,
            parentPublicId: $row->parentPublicId,
            childPublicId: $row->childPublicId,
            applicationState: $row->applicationState,
            selected: false,
        );
    }
}
