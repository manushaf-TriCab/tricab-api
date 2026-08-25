<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand;
use TriCab\Core\Product\Application\DTO\StyleDiscoveryProposal;
use TriCab\Core\Product\Application\DTO\StyleInventoryItem;
use TriCab\Core\Product\Application\DTO\StyleSharedDataComparison;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\Service\StyleHierarchyCycleGuard;
use TriCab\Core\Product\Domain\ValueObject\ProductStyleCode;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;

/**
 * Dry-run hierarchy proposals from existing style codes and the legacy inventory.
 *
 * Prefix matching is never persisted and is never treated as authoritative.
 * This service does not write to WordPress, tc MySQL, or product data.
 * applyApprovedHierarchy() exists for a later operator-approved phase and is
 * not invoked by preview().
 */
final class DiscoverLegacyStyleHierarchyService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly StyleHierarchyCycleGuard $cycleGuard = new StyleHierarchyCycleGuard(),
        private readonly ?DiscoverLegacyStyleInventoryService $inventory = null,
        private readonly ?ManageProductStyleService $manage = null,
    ) {
    }

    /**
     * @param list<string> $extraStyleCodes
     * @return array<string, mixed>
     */
    public function preview(array $extraStyleCodes = []): array
    {
        $inventory = $this->inventory?->discover();
        $codes = [];
        $productCounts = [];
        $sourceEvidence = [];
        $familyEvidence = [];
        $targetOnly = [];
        $reviewCodes = [];

        foreach ($this->styles->listAll(false) as $style) {
            $code = $this->stringCode($style->styleCode);
            $codes[$code] = true;
            $productCounts[$code] = $style->productCount;
        }
        if ($inventory !== null) {
            foreach ($inventory->items as $item) {
                $this->absorbInventoryItem($item, $codes, $productCounts, $sourceEvidence, $familyEvidence, $reviewCodes);
            }
            foreach ($inventory->targetOnly as $item) {
                $code = $this->stringCode($item->normalizedStyleCode ?? $item->rawStyleCode);
                $codes[$code] = true;
                $targetOnly[$code] = true;
                $reviewCodes[$code] = true;
                $sourceEvidence[$code] = implode(', ', $item->sources);
            }
        }
        foreach ($extraStyleCodes as $raw) {
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            try {
                $codes[$this->stringCode(ProductStyleCode::fromString($raw)->toString())] = true;
            } catch (\Throwable) {
                continue;
            }
        }
        $styleCodes = array_map([$this, 'stringCode'], array_keys($codes));
        sort($styleCodes);

        $proposals = $this->propose($styleCodes, $productCounts, $sourceEvidence, $familyEvidence, $reviewCodes, $targetOnly);
        $comparisons = $this->compareProposedFamilies($proposals);
        $childCodes = [];
        $parentCodes = [];
        foreach ($proposals as $proposal) {
            $childCodes[$proposal->childStyleCode] = true;
            $parentCodes[$proposal->parentStyleCode] = true;
        }
        $standalone = [];
        foreach ($styleCodes as $code) {
            if (!isset($childCodes[$code]) && !isset($parentCodes[$code])) {
                $standalone[] = $code;
            }
        }
        $ambiguous = 0;
        $cycles = 0;
        $high = 0;
        $medium = 0;
        $low = 0;
        $review = 0;
        foreach ($proposals as $proposal) {
            if ($proposal->ambiguousParent) {
                ++$ambiguous;
            }
            if ($proposal->cycleConflict) {
                ++$cycles;
            }
            match ($proposal->confidence) {
                'HIGH' => ++$high,
                'MEDIUM' => ++$medium,
                'LOW' => ++$low,
                default => ++$review,
            };
        }

        return [
            'writeGuard' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'filesystemWrites' => 0,
                'forbiddenWriteAttempts' => 0,
                'persisted' => false,
                'relationshipsApplied' => 0,
            ],
            'styleCodes' => $styleCodes,
            'proposals' => $proposals,
            'comparisons' => $comparisons,
            'proposedTrees' => $this->buildTrees($proposals, $standalone),
            'standaloneStyleCodes' => $standalone,
            'targetOnlyStyleCodes' => array_keys($targetOnly),
            'inventory' => $inventory?->toArray(),
            'counters' => [
                'stylesAnalysed' => count($styleCodes),
                'rootCandidates' => count($styleCodes) - count($childCodes),
                'childCandidates' => count($childCodes),
                'highConfidence' => $high,
                'mediumConfidence' => $medium,
                'lowConfidence' => $low,
                'review' => $review,
                'standaloneStyles' => count($standalone),
                'ambiguousParentCases' => $ambiguous,
                'cycleConflicts' => $cycles,
                'relationshipsApplied' => 0,
            ],
            'notes' => [
                'Prefix matching is evidence only and is not stored as hierarchy.',
                'Do not apply mappings without operator review.',
                'Shared-data comparison never moves specifications or media automatically.',
                'Persisted parent links and proposals are listed separately. Proposals are not applied.',
            ],
        ];
    }

    /**
     * Persist only an explicit operator-confirmed relationship list.
     * Never means "apply all HIGH". Empty list writes nothing.
     * Does not copy or move resource data. Does not write WordPress or tc MySQL.
     *
     * @param list<array{parentStyleCode?: string, childStyleCode?: string, parentStylePublicId?: string, childStylePublicId?: string}> $approved
     * @return list<array{parentStyleCode: string, childStyleCode: string, applied: bool, reason: string}>
     */
    public function applyApprovedHierarchy(ActorContext $actor, array $approved, bool $confirmed = false): array
    {
        if (!$confirmed) {
            throw InvalidProductValue::for('confirmed', 'false');
        }
        $results = [];
        foreach ($approved as $row) {
            $parentCode = strtoupper(trim((string) ($row['parentStyleCode'] ?? '')));
            $childCode = strtoupper(trim((string) ($row['childStyleCode'] ?? '')));
            if (preg_match('/^\d+$/', $parentCode) === 1 || preg_match('/^\d+$/', $childCode) === 1) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'applied' => false,
                    'reason' => 'EXCLUDED_REVIEW: numeric cable_style relationships are not applied.',
                ];
                continue;
            }
            $parent = $parentCode !== ''
                ? $this->styles->findByStyleCode($parentCode, false)
                : (isset($row['parentStylePublicId']) && is_string($row['parentStylePublicId'])
                    ? $this->styles->findByPublicId($row['parentStylePublicId'], false)
                    : null);
            $child = $childCode !== ''
                ? $this->styles->findByStyleCode($childCode, false)
                : (isset($row['childStylePublicId']) && is_string($row['childStylePublicId'])
                    ? $this->styles->findByPublicId($row['childStylePublicId'], false)
                    : null);
            $parentCode = $parent?->styleCode ?? $parentCode;
            $childCode = $child?->styleCode ?? $childCode;
            if ($parent === null || $child === null) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'applied' => false,
                    'reason' => 'NOT_APPLY_ELIGIBLE: both styles must already exist. Missing parents are not created.',
                ];
                continue;
            }
            if (preg_match('/^\d+$/', $parentCode) === 1 || preg_match('/^\d+$/', $childCode) === 1) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'applied' => false,
                    'reason' => 'EXCLUDED_REVIEW: numeric cable_style relationships are not applied.',
                ];
                continue;
            }
            if ($child->parentPublicId === $parent->publicId) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'applied' => false,
                    'reason' => 'SAME_PARENT_ALREADY: no write.',
                ];
                continue;
            }
            if ($child->parentPublicId !== null && $child->parentPublicId !== $parent->publicId) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'applied' => false,
                    'reason' => 'DIFFERENT_PARENT_CONFLICT: re-parenting requires separate explicit approval. Persisted parent is ' . ($child->parentStyleCode ?? $child->parentPublicId) . '.',
                ];
                continue;
            }
            $ancestorIds = array_map(static fn (array $a): string => $a['publicId'], $parent->ancestors);
            $ancestorIds = array_merge([$parent->publicId], $ancestorIds);
            try {
                $this->cycleGuard->assertCanSetParent($child->publicId, $parent->publicId, $ancestorIds);
            } catch (\Throwable $exception) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'applied' => false,
                    'reason' => $exception->getMessage(),
                ];
                continue;
            }
            if ($this->manage !== null) {
                $this->manage->changeParent($actor, new ChangeProductStyleParentCommand(
                    stylePublicId: $child->publicId,
                    parentPublicId: $parent->publicId,
                    expectedRowVersion: $child->rowVersion,
                ));
            } else {
                $this->styles->setParent(
                    $child->publicId,
                    $parent->publicId,
                    $child->rowVersion,
                    \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt($actor->userId),
                );
            }
            $results[] = [
                'parentStyleCode' => $parentCode,
                'childStyleCode' => $childCode,
                'applied' => true,
                'reason' => 'Parent link stored. Resource data was not moved. Inheritance was not changed.',
            ];
        }

        return $results;
    }

    /**
     * @param array<string, true> $codes
     * @param array<string, int> $productCounts
     * @param array<string, string> $sourceEvidence
     * @param array<string, string> $familyEvidence
     * @param array<string, true> $reviewCodes
     */
    private function absorbInventoryItem(
        StyleInventoryItem $item,
        array &$codes,
        array &$productCounts,
        array &$sourceEvidence,
        array &$familyEvidence,
        array &$reviewCodes,
    ): void {
        if ($item->status === 'INVALID' || $item->normalizedStyleCode === null) {
            return;
        }
        $code = $this->stringCode($item->normalizedStyleCode);
        $codes[$code] = true;
        $productCounts[$code] = max($productCounts[$code] ?? 0, $item->productCount);
        $sourceEvidence[$code] = implode(', ', $item->sources);
        if ($item->familyEvidence !== null && $item->familyEvidence !== '') {
            $familyEvidence[$code] = $item->familyEvidence;
        }
        if ($item->status === 'REVIEW') {
            $reviewCodes[$code] = true;
        }
    }

    /**
     * @param list<string> $styleCodes
     * @param array<string, int> $productCounts
     * @param array<string, string> $sourceEvidence
     * @param array<string, string> $familyEvidence
     * @param array<string, true> $reviewCodes
     * @param array<string, true> $targetOnly
     * @return list<StyleDiscoveryProposal>
     */
    private function propose(
        array $styleCodes,
        array $productCounts,
        array $sourceEvidence,
        array $familyEvidence,
        array $reviewCodes,
        array $targetOnly,
    ): array {
        $existingParents = [];
        foreach ($styleCodes as $code) {
            $existingParents[$code] = $this->styles->findByStyleCode($this->stringCode($code), false)?->parentStyleCode;
        }
        $proposals = [];
        foreach ($styleCodes as $child) {
            $child = $this->stringCode($child);
            $candidates = $this->prefixParents($child, $styleCodes);
            if ($candidates === []) {
                continue;
            }
            $parent = $candidates[array_key_last($candidates)];
            $ambiguous = count($candidates) > 1;
            $persistedParent = $existingParents[$child] ?? null;
            $warnings = [
                'Prefix matching is evidence only, not authority.',
            ];
            if ($ambiguous) {
                $warnings[] = 'Ambiguous parents: ' . implode(', ', $candidates)
                    . '. Longest prefix ' . $parent . ' is a candidate only; operator must choose nested vs sibling.';
            }
            $cycleConflict = $this->wouldCycle($child, $parent, $existingParents);
            if ($cycleConflict) {
                $warnings[] = 'Proposed parent would create a cycle against persisted or proposed links.';
            }
            $applicationState = 'NOT_APPLIED';
            if ($persistedParent === $parent) {
                $applicationState = 'ALREADY_PERSISTED';
                $warnings[] = 'This parent is already stored. Proposal is listed for review, not as a new write.';
            } elseif ($persistedParent !== null && $persistedParent !== $parent) {
                $applicationState = 'CONFLICTS_WITH_PERSISTED';
                $warnings[] = 'Persisted parent is ' . $persistedParent . '; proposal differs.';
            }
            if (isset($targetOnly[$child]) || isset($targetOnly[$parent])) {
                $warnings[] = 'One or both styles are target-only / possible test data.';
            }
            $numericPair = preg_match('/^\d+$/', $parent) === 1 || preg_match('/^\d+$/', $child) === 1;
            if ($numericPair) {
                $warnings[] = 'Numeric cable_style values are REVIEW/excluded and not apply-eligible.';
            }
            $confidence = $this->confidence(
                $parent,
                $child,
                $ambiguous,
                $cycleConflict,
                $applicationState,
                isset($reviewCodes[$child])
                    || isset($reviewCodes[$parent])
                    || isset($targetOnly[$child])
                    || $numericPair,
            );
            $evidence = $this->evidence($parent, $child);
            $azureCycle = $this->azureCycleConflict($child, $parent);
            if ($azureCycle) {
                $cycleConflict = true;
                $confidence = 'REVIEW';
                $warnings[] = 'Existing Azure ancestor chain would cycle if this parent were applied.';
            }
            if ($evidence['specs'] === null) {
                $warnings[] = 'Spec similarity: NO_EVIDENCE (zero-vs-zero style-owned specification rows is not similarity).';
            }
            if ($evidence['media'] === null) {
                $warnings[] = 'Media similarity: NO_EVIDENCE (zero-vs-zero style-owned image rows is not similarity).';
            }
            $proposals[] = new StyleDiscoveryProposal(
                parentStyleCode: $parent,
                childStyleCode: $child,
                reason: sprintf(
                    'Longest existing code prefix: %s is a prefix of %s. This is evidence only.',
                    $parent,
                    $child,
                ),
                confidence: $confidence,
                productCount: $productCounts[$child] ?? 0,
                sharedSpecificationEvidence: $evidence['specs'],
                sharedMediaEvidence: $evidence['media'],
                candidateSource: 'STYLE_CODE_PREFIX',
                warnings: $warnings,
                familyEvidence: $familyEvidence[$child] ?? $familyEvidence[$parent] ?? null,
                sourceEvidence: $sourceEvidence[$child] ?? 'unavailable',
                applicationState: $applicationState,
                ambiguousParent: $ambiguous,
                cycleConflict: $cycleConflict,
                persistedParentStyleCode: $persistedParent,
                prefixCandidates: $candidates,
            );
        }

        return $proposals;
    }

    /**
     * @param list<string> $styleCodes
     * @return list<string>
     */
    private function prefixParents(string $child, array $styleCodes): array
    {
        $matches = [];
        foreach ($styleCodes as $candidate) {
            $candidate = $this->stringCode($candidate);
            if ($candidate === $child) {
                continue;
            }
            if (!str_starts_with($child, $candidate)) {
                continue;
            }
            $matches[] = $candidate;
        }
        usort($matches, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        return $matches;
    }

    private function confidence(
        string $parent,
        string $child,
        bool $ambiguous,
        bool $cycleConflict,
        string $applicationState,
        bool $forceReview,
    ): string {
        if ($ambiguous || $cycleConflict || $applicationState === 'CONFLICTS_WITH_PERSISTED' || $forceReview) {
            return 'REVIEW';
        }
        $delta = strlen($child) - strlen($parent);
        if ($delta >= 1 && $delta <= 3) {
            return 'HIGH';
        }

        return 'MEDIUM';
    }

    /**
     * @param array<string, ?string> $existingParents
     */
    private function wouldCycle(string $child, string $parent, array $existingParents): bool
    {
        $parentByChild = $existingParents;
        $parentByChild[$child] = $parent;
        $seen = [$child => true];
        $current = $parent;
        while (is_string($current) && $current !== '') {
            if (isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $current = $parentByChild[$current] ?? null;
        }

        return false;
    }

    private function azureCycleConflict(string $childCode, string $parentCode): bool
    {
        $parent = $this->styles->findByStyleCode($this->stringCode($parentCode), false);
        $child = $this->styles->findByStyleCode($this->stringCode($childCode), false);
        if ($parent === null || $child === null) {
            return false;
        }
        $ancestorIds = array_map(static fn (array $a): string => $a['publicId'], $parent->ancestors);
        $ancestorIds = array_merge([$parent->publicId], $ancestorIds);
        try {
            $this->cycleGuard->assertCanSetParent($child->publicId, $parent->publicId, $ancestorIds);
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private function stringCode(mixed $code): string
    {
        return (string) $code;
    }

    /**
     * @return array{specs: ?int, media: ?int}
     */
    private function evidence(string $parentCode, string $childCode): array
    {
        $parent = $this->styles->findByStyleCode($this->stringCode($parentCode), false);
        $child = $this->styles->findByStyleCode($this->stringCode($childCode), false);
        if ($parent === null || $child === null) {
            return ['specs' => null, 'media' => null];
        }
        $parentSpecs = $this->styles->listStyleSpecificationValues($parent->publicId);
        $childSpecs = $this->styles->listStyleSpecificationValues($child->publicId);
        $sharedSpecs = 0;
        $parentByDef = [];
        foreach ($parentSpecs as $value) {
            $parentByDef[(string) $value['definitionPublicId']] = $this->specFingerprint($value);
        }
        foreach ($childSpecs as $value) {
            $def = (string) $value['definitionPublicId'];
            if (isset($parentByDef[$def]) && $parentByDef[$def] === $this->specFingerprint($value)) {
                $sharedSpecs++;
            }
        }
        $parentMedia = $this->styles->listStyleAssets($parent->publicId, StyleResourceType::images());
        $childMedia = $this->styles->listStyleAssets($child->publicId, StyleResourceType::images());
        $parentPaths = [];
        foreach ($parentMedia as $asset) {
            $key = (string) ($asset['storagePath'] ?? $asset['checksumSha256'] ?? $asset['title']);
            $parentPaths[$key] = true;
        }
        $sharedMedia = 0;
        foreach ($childMedia as $asset) {
            $key = (string) ($asset['storagePath'] ?? $asset['checksumSha256'] ?? $asset['title']);
            if (isset($parentPaths[$key])) {
                $sharedMedia++;
            }
        }

        return [
            'specs' => $parentSpecs === [] && $childSpecs === [] ? null : $sharedSpecs,
            'media' => $parentMedia === [] && $childMedia === [] ? null : $sharedMedia,
        ];
    }

    /**
     * @param list<StyleDiscoveryProposal> $proposals
     * @param list<string> $standalone
     * @return list<array<string, mixed>>
     */
    private function buildTrees(array $proposals, array $standalone): array
    {
        $children = [];
        $childSet = [];
        foreach ($proposals as $proposal) {
            $children[$this->stringCode($proposal->parentStyleCode)][] = $proposal;
            $childSet[$this->stringCode($proposal->childStyleCode)] = true;
        }
        $roots = [];
        foreach (array_keys($children) as $parent) {
            $parent = $this->stringCode($parent);
            if (!isset($childSet[$parent])) {
                $roots[] = $parent;
            }
        }
        sort($roots);
        $trees = [];
        foreach ($roots as $root) {
            $root = $this->stringCode($root);
            $trees[] = [
                'rootStyleCode' => $root,
                'kind' => 'proposed',
                'children' => $this->treeChildren($root, $children),
            ];
        }
        if ($standalone !== []) {
            $trees[] = [
                'rootStyleCode' => 'Standalone',
                'kind' => 'standalone',
                'children' => array_map(static fn (string $code): array => [
                    'styleCode' => $code,
                    'confidence' => null,
                    'applicationState' => 'NOT_APPLIED',
                    'children' => [],
                ], $standalone),
            ];
        }

        return $trees;
    }

    /**
     * @param array<string, list<StyleDiscoveryProposal>> $children
     * @return list<array<string, mixed>>
     */
    private function treeChildren(string $parent, array $children): array
    {
        $out = [];
        $parent = $this->stringCode($parent);
        foreach ($children[$parent] ?? [] as $proposal) {
            $out[] = [
                'styleCode' => $proposal->childStyleCode,
                'confidence' => $proposal->confidence,
                'applicationState' => $proposal->applicationState,
                'ambiguousParent' => $proposal->ambiguousParent,
                'children' => $this->treeChildren($proposal->childStyleCode, $children),
            ];
        }

        return $out;
    }

    /**
     * @param list<StyleDiscoveryProposal> $proposals
     * @return list<StyleSharedDataComparison>
     */
    private function compareProposedFamilies(array $proposals): array
    {
        $childrenByParent = [];
        foreach ($proposals as $proposal) {
            $childrenByParent[$proposal->parentStyleCode][] = $proposal->childStyleCode;
        }
        $comparisons = [];
        foreach ($childrenByParent as $parent => $children) {
            $parent = $this->stringCode($parent);
            $children = array_values(array_unique(array_map([$this, 'stringCode'], $children)));
            $specMaps = [];
            $mediaMaps = [];
            foreach (array_merge([$parent], $children) as $code) {
                $code = $this->stringCode($code);
                $style = $this->styles->findByStyleCode($code, false);
                $specMaps[$code] = [];
                $mediaMaps[$code] = [];
                if ($style === null) {
                    continue;
                }
                foreach ($this->styles->listStyleSpecificationValues($style->publicId) as $value) {
                    $specMaps[$code][(string) $value['definitionPublicId']] = $this->specFingerprint($value);
                }
                foreach ($this->styles->listStyleAssets($style->publicId, StyleResourceType::images()) as $asset) {
                    $key = (string) ($asset['storagePath'] ?? $asset['checksumSha256'] ?? $asset['title']);
                    $mediaMaps[$code][$key] = true;
                }
            }
            $allDefs = [];
            foreach ($specMaps as $map) {
                foreach (array_keys($map) as $def) {
                    $allDefs[$def] = true;
                }
            }
            $identical = [];
            $differing = [];
            $missing = [];
            foreach (array_keys($allDefs) as $def) {
                $values = [];
                $present = 0;
                foreach ($children as $child) {
                    if (array_key_exists($def, $specMaps[$child])) {
                        $values[] = $specMaps[$child][$def];
                        $present++;
                    }
                }
                if ($present < count($children)) {
                    $missing[] = $def;
                } elseif (count(array_unique($values)) === 1) {
                    $identical[] = $def;
                } else {
                    $differing[] = $def;
                }
            }
            $commonMedia = [];
            $firstChild = $children[0] ?? null;
            if ($firstChild !== null) {
                foreach (array_keys($mediaMaps[$firstChild]) as $key) {
                    $allHave = true;
                    foreach ($children as $child) {
                        if (!isset($mediaMaps[$child][$key])) {
                            $allHave = false;
                            break;
                        }
                    }
                    if ($allHave) {
                        $commonMedia[] = $key;
                    }
                }
            }
            $childSpecific = [];
            foreach ($children as $child) {
                $specific = [];
                foreach (array_keys($mediaMaps[$child]) as $key) {
                    if (!in_array($key, $commonMedia, true)) {
                        $specific[] = $key;
                    }
                }
                $childSpecific[$child] = $specific;
            }
            $comparisons[] = new StyleSharedDataComparison(
                proposedParentStyleCode: $parent,
                childStyleCodes: $children,
                identicalSpecificationCount: count($identical),
                differingSpecificationCount: count($differing),
                missingSpecificationCount: count($missing),
                identicalSpecificationCodes: $identical,
                differingSpecificationCodes: $differing,
                missingSpecificationCodes: $missing,
                commonMediaCount: count($commonMedia),
                commonMediaKeys: $commonMedia,
                childSpecificMediaKeys: $childSpecific,
            );
        }

        return $comparisons;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function specFingerprint(array $value): string
    {
        return json_encode([
            $value['valueText'] ?? null,
            $value['valueLongText'] ?? null,
            $value['valueInteger'] ?? null,
            $value['valueDecimal'] ?? null,
            $value['valueBoolean'] ?? null,
            $value['valueDate'] ?? null,
            $value['valueUrl'] ?? null,
            $value['optionPublicId'] ?? null,
            $value['optionPublicIds'] ?? [],
        ], JSON_THROW_ON_ERROR);
    }
}
