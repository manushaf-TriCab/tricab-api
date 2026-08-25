<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductCodeStyleMappingRow;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Contract\ProductStyleFamilyEvidenceSource;
use TriCab\Core\Product\Contract\ProductStyleMappingIdentitySource;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Service\StyleHierarchyCycleGuard;
use TriCab\Core\Product\Domain\ValueObject\ProductStyleCode;

/**
 * Dry-run Style Family Tree + exact Product.Code → child Style mapping.
 * Never writes ProductStyles, ParentStyleId, assignments, specs, or media.
 */
final class DiscoverProductStyleFamilyTreeService
{
    /** @var array<string, list<string>> */
    private const OPERATOR_CONFIRMED = [
        'BV' => ['BVA', 'BVC', 'BVM', 'BVP'],
        'RC' => ['RCA', 'RCP', 'RCM', 'RCC'],
    ];

    private const SMOKE_CHILD = 'BVA-X';
    private const SMOKE_PARENT = 'BVA';
    private const CONSTRUCTION_SUFFIXES = ['A', 'C', 'M', 'P', 'X'];

    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ProductStyleMappingIdentitySource $identity,
        private readonly DiscoverProductCodeStyleMappingService $productCodeMapping,
        private readonly ProductStyleFamilyEvidenceSource $evidence,
        private readonly StyleHierarchyCycleGuard $cycleGuard = new StyleHierarchyCycleGuard(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        $mapping = $this->productCodeMapping->dryRun();
        /** @var list<ProductCodeStyleMappingRow> $mappingRows */
        $mappingRows = $mapping['rows'] ?? [];
        $eligible = [];
        $byCode = [];
        foreach ($mappingRows as $row) {
            if ($row->population !== 'STYLE_SHELL_PRODUCT' || $row->canonicalProductCode === null) {
                continue;
            }
            $eligible[] = $row;
            $byCode[$row->canonicalProductCode] = $row;
        }

        $stylesByCode = [];
        foreach ($this->styles->listAll(false) as $style) {
            $stylesByCode[$style->styleCode] = $style;
        }

        $productIds = array_values(array_unique(array_map(
            static fn (ProductCodeStyleMappingRow $row): string => $row->productPublicId,
            $eligible,
        )));
        $evidenceByProduct = $this->evidence->load($productIds);

        $siblingGroups = $this->siblingGroups(array_keys($byCode));
        $families = [];
        $relationships = [];
        $claimedChildren = [];

        foreach (self::OPERATOR_CONFIRMED as $parent => $children) {
            $extras = array_values(array_diff($siblingGroups[$parent] ?? [], $children));
            $family = $this->buildFamily(
                $parent,
                $children,
                'OPERATOR_CONFIRMED',
                $byCode,
                $stylesByCode,
                $evidenceByProduct,
                'Operator-confirmed style family. Prefix evidence is not required.',
                $extras,
            );
            $families[] = $family;
            foreach ($family['relationships'] as $rel) {
                $relationships[] = $rel;
                $claimedChildren[$rel['childStyleCode']] = $parent;
            }
        }

        $approve = [];
        $review = [];
        $rejected = [];
        foreach ($siblingGroups as $parent => $children) {
            if (isset(self::OPERATOR_CONFIRMED[$parent])) {
                $extras = array_values(array_diff($children, self::OPERATOR_CONFIRMED[$parent]));
                foreach ($extras as $extra) {
                    if (isset($claimedChildren[$extra])) {
                        continue;
                    }
                    $rel = $this->relationship(
                        $parent,
                        $extra,
                        'REVIEW',
                        $byCode,
                        $stylesByCode,
                        $evidenceByProduct,
                        'Additional sibling under an operator-confirmed parent. Not silently added to the confirmed family.',
                    );
                    $review[] = $rel;
                    $relationships[] = $rel;
                    $claimedChildren[$extra] = $parent;
                }
                continue;
            }
            $unclaimed = array_values(array_filter(
                $children,
                static fn (string $child): bool => !isset($claimedChildren[$child]),
            ));
            if (count($unclaimed) < 2) {
                continue;
            }
            $unsafe = $this->prefixUnsafe($parent, $unclaimed);
            $class = $this->classificationLabel($unclaimed, $byCode, $evidenceByProduct);
            $construction = $this->constructionRatio($parent, $unclaimed);
            $decision = $this->candidateDecision($unsafe, $class, $construction, count($unclaimed));
            $family = $this->buildFamily(
                $parent,
                $unclaimed,
                $decision,
                $byCode,
                $stylesByCode,
                $evidenceByProduct,
                $this->candidateReason($decision, $unsafe, $class, $construction, count($unclaimed)),
                [],
            );
            if ($this->wouldCycle($relationships, $family['relationships'])) {
                $family = $this->relabelFamily($family, 'REVIEW', 'Cycle risk. Demoted from apply-eligible candidate.');
                $decision = 'REVIEW';
            }
            $families[] = $family;
            foreach ($family['relationships'] as $rel) {
                $relationships[] = $rel;
                $claimedChildren[$rel['childStyleCode']] = $parent;
            }
            if ($decision === 'APPROVE_CANDIDATE') {
                $approve[] = $family;
            } elseif ($decision === 'REJECT') {
                $rejected[] = $family;
            } else {
                $review = array_merge($review, $family['relationships']);
            }
        }

        $smoke = $this->smokeBvaX($stylesByCode, $byCode);
        if ($smoke !== null) {
            $relationships[] = $smoke;
        }

        $standalone = [];
        foreach ($byCode as $code => $row) {
            if (isset($claimedChildren[$code])) {
                continue;
            }
            $isParent = false;
            foreach ($families as $family) {
                if ($family['parentStyleCode'] === $code) {
                    $isParent = true;
                    break;
                }
            }
            if ($isParent) {
                continue;
            }
            $standalone[] = [
                'styleCode' => $code,
                'productPublicId' => $row->productPublicId,
                'productName' => $row->productName,
                'styleExists' => isset($stylesByCode[$code]),
                'reason' => 'No safe sibling family: prefix similarity alone is not enough, and no operator-confirmed parent applies.',
                'parentStyleId' => null,
                'applicationState' => 'NOT_APPLIED',
            ];
        }

        $missingParents = [];
        $missingChildren = [];
        $seenParents = [];
        foreach ($families as $family) {
            if ($family['decision'] === 'REJECT') {
                continue;
            }
            $parent = $family['parentStyleCode'];
            if (!isset($stylesByCode[$parent]) && !isset($seenParents[$parent])) {
                $seenParents[$parent] = true;
                $missingParents[] = [
                    'styleCode' => $parent,
                    'displayName' => $parent,
                    'parentStyleId' => null,
                    'inheritance' => $this->localInheritance(),
                    'isActive' => true,
                    'children' => $family['childStyleCodes'],
                    'decision' => $family['decision'] === 'OPERATOR_CONFIRMED' ? 'PROPOSE_CREATE_PARENT' : $family['decision'],
                    'evidence' => $family['reason'],
                    'parentProductExists' => isset($byCode[$parent]),
                    'parentStyleExists' => false,
                    'status' => 'NOT_APPLIED',
                ];
            }
            foreach ($family['childStyleCodes'] as $child) {
                if (!isset($stylesByCode[$child])) {
                    $missingChildren[] = [
                        'productPublicId' => $byCode[$child]->productPublicId ?? null,
                        'productName' => $byCode[$child]->productName ?? null,
                        'productCode' => $child,
                        'proposedProductStyle' => $child,
                        'proposedParent' => $parent,
                        'relationshipDecision' => $family['decision'],
                        'styleExists' => false,
                        'status' => 'NOT_APPLIED',
                    ];
                }
            }
        }
        foreach ($byCode as $code => $row) {
            if (isset($stylesByCode[$code]) || isset($claimedChildren[$code])) {
                continue;
            }
            $missingChildren[] = [
                'productPublicId' => $row->productPublicId,
                'productName' => $row->productName,
                'productCode' => $code,
                'proposedProductStyle' => $code,
                'proposedParent' => null,
                'relationshipDecision' => 'STANDALONE',
                'styleExists' => false,
                'status' => 'NOT_APPLIED',
            ];
        }

        $productMappings = [];
        foreach ($eligible as $row) {
            $code = (string) $row->canonicalProductCode;
            $parent = $claimedChildren[$code] ?? null;
            $productMappings[] = [
                'productPublicId' => $row->productPublicId,
                'productName' => $row->productName,
                'productCode' => $code,
                'exactProductStyle' => $code,
                'proposedParentStyle' => $parent,
                'currentAssignment' => $row->currentAssignedStyleCode,
                'expectedAssignment' => $code,
                'assignmentDecision' => $row->decision,
                'mapsToParent' => false,
                'styleExists' => $row->styleExists,
                'reason' => 'Expected assignment is the exact child StyleCode, never the parent.',
            ];
        }

        $styleRoles = $this->styleRoles($byCode, $families, $claimedChildren, $standalone);
        $tree = $this->proposedTree($families, $standalone, $smoke, $stylesByCode, $byCode);
        $cycle = $this->validateCycles($relationships, $stylesByCode);

        $confirmedRels = array_values(array_filter(
            $relationships,
            static fn (array $rel): bool => ($rel['decision'] ?? '') === 'OPERATOR_CONFIRMED',
        ));
        $persistedConfirmed = 0;
        $confirmedNeedingApply = 0;
        foreach ($confirmedRels as $rel) {
            if (($rel['applicationState'] ?? '') === 'ALREADY_PERSISTED') {
                ++$persistedConfirmed;
            } else {
                ++$confirmedNeedingApply;
            }
        }

        $assignmentExact = 0;
        $assignmentMissing = 0;
        $assignmentConflict = 0;
        foreach ($eligible as $row) {
            if ($row->decision === 'ALREADY_ASSIGNED_SAME' || $row->decision === 'EXACT_STYLE_MATCH') {
                ++$assignmentExact;
            } elseif ($row->decision === 'ASSIGNMENT_CONFLICT') {
                ++$assignmentConflict;
            } elseif ($row->decision === 'MISSING_PRODUCT_STYLE') {
                ++$assignmentMissing;
            }
        }

        $rootParents = array_values(array_unique(array_map(
            static fn (array $family): string => $family['parentStyleCode'],
            array_filter($families, static fn (array $family): bool => $family['decision'] !== 'REJECT'),
        )));
        $childCodes = array_keys($claimedChildren);

        return [
            'writeGuard' => [
                'productStyleWrites' => 0,
                'productStyleInserts' => 0,
                'productStyleUpdates' => 0,
                'parentStyleIdWrites' => 0,
                'productAssignmentWrites' => 0,
                'productAssignmentInserts' => 0,
                'productAssignmentUpdates' => 0,
                'productAssignmentDeletes' => 0,
                'productWrites' => 0,
                'variantWrites' => 0,
                'specificationWrites' => 0,
                'mediaWrites' => 0,
                'blobWrites' => 0,
                'classificationWrites' => 0,
                'catalogueWrites' => 0,
                'regionWrites' => 0,
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'filesystemWrites' => 0,
                'forbiddenWriteAttempts' => 0,
                'persisted' => false,
                'relationshipsApplied' => 0,
            ],
            'sourceReads' => [
                'wordpressReads' => 0,
                'tcReads' => 0,
                'filesystemReads' => 0,
                'azureReads' => $this->identity->azureReads(),
            ],
            'businessRule' => [
                'styleFamilyModelConfirmed' => true,
                'productMapsToExactChildStyle' => true,
                'operatorConfirmedFamilies' => self::OPERATOR_CONFIRMED,
                'previousEmptyHierarchyIsNotPermanent' => true,
            ],
            'notes' => [
                'Products map to exact Product.Code styles, never to the family parent.',
                'RC and BV families are operator-confirmed even without legacy prefix evidence.',
                'Prefix similarity generates candidates only. F12 never becomes a child of F1.',
                'BVA-X remains existing smoke unless independently confirmed.',
                'DRY RUN — NO CHANGES APPLIED.',
            ],
            'cycleValidation' => $cycle,
            'families' => $families,
            'proposedTree' => $tree,
            'operatorConfirmedRelationships' => $confirmedRels,
            'proposedAdditionalRelationships' => $this->flattenRelationships($approve),
            'reviewRelationships' => $review,
            'rejectedRelationships' => $this->flattenRelationships($rejected),
            'standaloneStyles' => $standalone,
            'missingParentStyleCandidates' => $missingParents,
            'missingChildStyleCandidates' => $missingChildren,
            'productMappings' => $productMappings,
            'styleRoles' => $styleRoles,
            'smokeRelationships' => $smoke !== null ? [$smoke] : [],
            'inheritanceCandidates' => array_map(static fn (array $family): array => $family['inheritanceCandidate'], $families),
            'counters' => [
                'eligibleStyleShellProducts' => count($eligible),
                'distinctProductCodes' => count($byCode),
                'productStylesExistingForProductCodes' => count(array_filter(
                    array_keys($byCode),
                    static fn (string $code) => isset($stylesByCode[$code]),
                )),
                'productStylesMissingForProductCodes' => count(array_filter(
                    array_keys($byCode),
                    static fn (string $code) => !isset($stylesByCode[$code]),
                )),
                'rootParent' => count($rootParents),
                'child' => count($childCodes),
                'standalone' => count($standalone),
                'review' => count($review),
                'operatorConfirmedRelationships' => count($confirmedRels),
                'alreadyPersistedConfirmedRelationships' => $persistedConfirmed,
                'confirmedRelationshipsRequiringApply' => $confirmedNeedingApply,
                'additionalApproveCandidateRelationships' => array_sum(array_map(
                    static fn (array $family): int => count($family['relationships']),
                    $approve,
                )),
                'reviewRelationships' => count($review),
                'rejectRelationships' => array_sum(array_map(
                    static fn (array $family): int => count($family['relationships']),
                    $rejected,
                )),
                'missingParentProductStyles' => count($missingParents),
                'missingChildProductStyles' => count($missingChildren),
                'exactProductStyleAssignments' => $assignmentExact,
                'missingAssignments' => $assignmentMissing,
                'assignmentConflicts' => $assignmentConflict,
                'selfCycles' => $cycle['selfCycles'],
                'directCycles' => $cycle['directCycles'],
                'deepCycles' => $cycle['deepCycles'],
            ],
            'productCodeMappingCounters' => $mapping['counters'] ?? [],
        ];
    }

    /**
     * @param list<string> $codes
     * @return array<string, list<string>>
     */
    private function siblingGroups(array $codes): array
    {
        $groups = [];
        foreach ($codes as $code) {
            if (preg_match('/^([A-Z]{2})([A-Z0-9])$/', $code, $match) !== 1) {
                continue;
            }
            $stem = $match[1];
            if (preg_match('/[0-9]/', $stem) === 1) {
                continue;
            }
            try {
                ProductStyleCode::fromString($stem);
            } catch (\Throwable) {
                continue;
            }
            $groups[$stem][] = $code;
        }
        foreach ($groups as $stem => $children) {
            sort($groups[$stem]);
        }
        ksort($groups);

        return $groups;
    }

    /**
     * @param list<string> $children
     */
    private function prefixUnsafe(string $parent, array $children): bool
    {
        if (preg_match('/[0-9]/', $parent) === 1) {
            return true;
        }
        foreach ($children as $child) {
            if ($child !== $parent && str_starts_with($child, $parent) && preg_match('/^[0-9]+$/', substr($child, strlen($parent))) === 1) {
                return true;
            }
            if (strlen($parent) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $children
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @param array<string, array<string, mixed>> $evidence
     */
    private function classificationLabel(array $children, array $byCode, array $evidence): string
    {
        $families = [];
        $categories = [];
        $present = 0;
        foreach ($children as $child) {
            $row = $byCode[$child] ?? null;
            if ($row === null) {
                continue;
            }
            $ev = $evidence[$row->productPublicId] ?? null;
            if ($ev === null) {
                continue;
            }
            ++$present;
            $families[] = (string) ($ev['familyCode'] ?? '');
            $categories[] = (string) ($ev['categoryCode'] ?? '');
        }
        if ($present === 0) {
            return 'CLASSIFICATION_NEUTRAL';
        }
        $families = array_values(array_filter($families, static fn (string $v): bool => $v !== ''));
        if ($families === []) {
            return 'CLASSIFICATION_NEUTRAL';
        }
        if (count(array_unique($families)) > 1) {
            return 'CLASSIFICATION_CONFLICT';
        }
        $categories = array_values(array_filter($categories, static fn (string $v): bool => $v !== ''));
        if ($categories !== [] && count(array_unique($categories)) > 1) {
            return 'CLASSIFICATION_NEUTRAL';
        }

        return 'CLASSIFICATION_SUPPORT';
    }

    /**
     * @param list<string> $children
     */
    private function constructionRatio(string $parent, array $children): float
    {
        $ok = 0;
        foreach ($children as $child) {
            $suffix = substr($child, strlen($parent));
            if (strlen($suffix) === 1 && in_array($suffix, self::CONSTRUCTION_SUFFIXES, true)) {
                ++$ok;
            }
        }

        return $children === [] ? 0.0 : $ok / count($children);
    }

    private function candidateDecision(bool $unsafe, string $classification, float $construction, int $count): string
    {
        if ($unsafe) {
            return 'REVIEW';
        }
        if ($classification === 'CLASSIFICATION_CONFLICT') {
            return 'REVIEW';
        }
        if ($count >= 2 && $construction >= 0.75 && ($classification === 'CLASSIFICATION_SUPPORT' || $count >= 3)) {
            return 'APPROVE_CANDIDATE';
        }
        if ($count >= 2) {
            return 'REVIEW';
        }

        return 'STANDALONE';
    }

    private function candidateReason(string $decision, bool $unsafe, string $classification, float $construction, int $count): string
    {
        if ($unsafe) {
            return 'Prefix-only numeric/length pattern (for example F1 → F12) is not an approved family.';
        }
        if ($classification === 'CLASSIFICATION_CONFLICT') {
            return 'Sibling Product.Codes share a stem but Family classification conflicts.';
        }
        if ($decision === 'APPROVE_CANDIDATE') {
            return 'Sibling-aware construction family: ' . $count . ' children, construction ratio '
                . number_format($construction, 2) . ', ' . $classification . '.';
        }

        return 'Sibling stem group exists but evidence is not strong enough to approve. ' . $classification
            . ', construction ratio ' . number_format($construction, 2) . '.';
    }

    /**
     * @param list<string> $children
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @param array<string, ProductStyleRecord> $stylesByCode
     * @param array<string, array<string, mixed>> $evidence
     * @param list<string> $extraSiblings
     * @return array<string, mixed>
     */
    private function buildFamily(
        string $parent,
        array $children,
        string $decision,
        array $byCode,
        array $stylesByCode,
        array $evidence,
        string $reason,
        array $extraSiblings,
    ): array {
        sort($children);
        $spec = $this->specShape($children, $byCode, $evidence);
        $media = $this->mediaShape($children, $byCode, $evidence);
        $class = $this->classificationLabel($children, $byCode, $evidence);
        $groupClass = $this->groupClassification($children, $byCode, $evidence);
        $construction = $this->constructionRatio($parent, $children);
        $parentStyle = $stylesByCode[$parent] ?? null;
        $absentPattern = $this->absentConstructionSiblings($parent, $children);
        $rels = [];
        foreach ($children as $child) {
            $rels[] = $this->relationship(
                $parent,
                $child,
                $decision,
                $byCode,
                $stylesByCode,
                $evidence,
                $reason,
                $class,
                $spec,
                $media['label'],
                $construction,
            );
        }
        $specInheritance = ($spec['sharedDefinitions'] > 0 && $spec['childSpecificDefinitions'] > 0)
            ? 'INHERIT_AND_EXTEND_CANDIDATE'
            : (($spec['sharedDefinitions'] > 0) ? 'INHERIT' : 'NONE');
        $imageInheritance = match ($media['label']) {
            'COMMON_MEDIA' => 'INHERIT_CANDIDATE',
            'PARTIAL_COMMON_MEDIA' => 'EXTEND_CANDIDATE',
            default => 'NONE',
        };

        return [
            'parentStyleCode' => $parent,
            'childStyleCodes' => $children,
            'decision' => $decision,
            'reason' => $reason,
            'parentExists' => $parentStyle !== null,
            'parentProductExists' => isset($byCode[$parent]),
            'parentMetadata' => [
                'styleCode' => $parent,
                'displayName' => $parentStyle?->displayName,
                'parentStyleId' => $parentStyle?->parentPublicId,
                'parentStyleCode' => $parentStyle?->parentStyleCode,
                'isActive' => $parentStyle?->isActive,
                'existingChildCount' => $parentStyle?->childCount ?? 0,
                'productCount' => $parentStyle?->productCount ?? 0,
                'inventoryOnly' => $parentStyle !== null && !isset($byCode[$parent]),
            ],
            'extraDiscoveredSiblings' => $extraSiblings,
            'absentPatternSiblings' => $absentPattern,
            'classificationEvidence' => $class,
            'groupClassification' => $groupClass,
            'specificationShape' => $spec,
            'mediaEvidence' => $media['label'],
            'media' => $media,
            'constructionRatio' => $construction,
            'relationships' => $rels,
            'inheritanceCandidate' => [
                'parentStyleCode' => $parent,
                'specifications' => $specInheritance,
                'images' => $imageInheritance,
                'status' => 'INHERITANCE_CANDIDATE',
                'applicationState' => 'NOT_APPLIED',
            ],
            'applicationState' => 'NOT_APPLIED',
        ];
    }

    /**
     * @param array<string, mixed> $family
     * @return array<string, mixed>
     */
    private function relabelFamily(array $family, string $decision, string $reason): array
    {
        $family['decision'] = $decision;
        $family['reason'] = $reason;
        foreach ($family['relationships'] as $i => $rel) {
            $family['relationships'][$i]['decision'] = $decision;
            $family['relationships'][$i]['reason'] = $reason;
        }

        return $family;
    }

    /**
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @param array<string, ProductStyleRecord> $stylesByCode
     * @param array<string, array<string, mixed>> $evidence
     * @return array<string, mixed>
     */
    /**
     * @param array<string, int> $spec
     */
    private function relationship(
        string $parent,
        string $child,
        string $decision,
        array $byCode,
        array $stylesByCode,
        array $evidence,
        string $reason,
        string $classificationLabel = 'CLASSIFICATION_NEUTRAL',
        array $spec = [],
        string $mediaLabel = 'NO_MEDIA_EVIDENCE',
        float $construction = 0.0,
    ): array {
        $childStyle = $stylesByCode[$child] ?? null;
        $currentParent = $childStyle?->parentStyleCode;
        $applicationState = 'NOT_APPLIED';
        if ($currentParent === $parent) {
            $applicationState = 'ALREADY_PERSISTED';
        } elseif ($currentParent !== null && $currentParent !== $parent) {
            $applicationState = 'CONFLICTS_WITH_PERSISTED';
        }
        $row = $byCode[$child] ?? null;
        $ev = $row !== null ? ($evidence[$row->productPublicId] ?? null) : null;
        $suffix = str_starts_with($child, $parent) ? substr($child, strlen($parent)) : '';
        $codeEvidence = (strlen($suffix) === 1 && in_array($suffix, self::CONSTRUCTION_SUFFIXES, true))
            ? 'SUPPORT'
            : (str_starts_with($child, $parent) ? 'PREFIX_ONLY' : 'NO_EVIDENCE');
        $specEvidence = 'NO_EVIDENCE';
        if (($spec['sharedDefinitions'] ?? 0) > 0 && ($spec['commonValueDefinitions'] ?? 0) > 0) {
            $specEvidence = ($spec['childSpecificDefinitions'] ?? 0) > 0 ? 'PARTIAL_SUPPORT' : 'SUPPORT';
        } elseif (($spec['sharedDefinitions'] ?? 0) > 0) {
            $specEvidence = 'PARTIAL_SUPPORT';
        }
        $applyAction = match (true) {
            $applicationState === 'ALREADY_PERSISTED' => 'NO_ACTION_ALREADY_PERSISTED',
            $decision === 'REJECT' => 'REJECT',
            $decision === 'REVIEW' => 'REVIEW',
            $childStyle === null => 'CREATE_CHILD_AND_LINK',
            default => 'LINK_EXISTING_CHILD',
        };

        return [
            'parentStyleCode' => $parent,
            'childStyleCode' => $child,
            'decision' => $decision,
            'confidence' => $decision === 'OPERATOR_CONFIRMED' ? 'OPERATOR' : ($decision === 'APPROVE_CANDIDATE' ? 'HIGH' : 'REVIEW'),
            'parentExists' => isset($stylesByCode[$parent]),
            'childExists' => $childStyle !== null,
            'productCount' => $row !== null ? 1 : 0,
            'productPublicId' => $row?->productPublicId,
            'productName' => $row?->productName,
            'productCode' => $row?->canonicalProductCode ?? $child,
            'currentParent' => $currentParent,
            'expectedProductStyle' => $child,
            'expectedAssignment' => $child,
            'currentAssignment' => $row?->currentAssignedStyleCode,
            'codeEvidence' => $codeEvidence,
            'suffix' => $suffix !== '' ? $suffix : null,
            'constructionRatio' => $construction,
            'classificationEvidence' => $classificationLabel,
            'productFamilyCode' => $ev['familyCode'] ?? null,
            'productCategoryCode' => $ev['categoryCode'] ?? null,
            'productSeriesCode' => $ev['seriesCode'] ?? null,
            'sharedSpecEvidence' => $specEvidence,
            'sharedMediaEvidence' => $mediaLabel,
            'applyAction' => $applyAction,
            'reason' => $reason,
            'applicationState' => $applicationState,
            'status' => 'NOT_APPLIED',
        ];
    }

    /**
     * @param list<string> $children
     * @return list<string>
     */
    private function absentConstructionSiblings(string $parent, array $children): array
    {
        $present = [];
        foreach ($children as $child) {
            if (str_starts_with($child, $parent) && strlen($child) === strlen($parent) + 1) {
                $present[] = substr($child, -1);
            }
        }
        $absent = [];
        foreach (self::CONSTRUCTION_SUFFIXES as $suffix) {
            if (!in_array($suffix, $present, true)) {
                $absent[] = $parent . $suffix;
            }
        }

        return $absent;
    }

    /**
     * @param list<string> $children
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @param array<string, array<string, mixed>> $evidence
     * @return array<string, mixed>
     */
    private function groupClassification(array $children, array $byCode, array $evidence): array
    {
        $families = [];
        $categories = [];
        $series = [];
        foreach ($children as $child) {
            $row = $byCode[$child] ?? null;
            if ($row === null) {
                continue;
            }
            $ev = $evidence[$row->productPublicId] ?? null;
            if ($ev === null) {
                continue;
            }
            if (!empty($ev['familyCode'])) {
                $families[] = (string) $ev['familyCode'];
            }
            if (!empty($ev['categoryCode'])) {
                $categories[] = (string) $ev['categoryCode'];
            }
            if (!empty($ev['seriesCode'])) {
                $series[] = (string) $ev['seriesCode'];
            }
        }

        return [
            'sharedFamilyCodes' => array_values(array_unique($families)),
            'sharedCategoryCodes' => array_values(array_unique($categories)),
            'sharedSeriesCodes' => array_values(array_unique($series)),
            'familySupport' => count(array_unique($families)) <= 1 && $families !== [],
            'categorySupport' => count(array_unique($categories)) <= 1,
        ];
    }

    /**
     * @param list<string> $children
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @param array<string, array<string, mixed>> $evidence
     * @return array<string, int>
     */
    private function specShape(array $children, array $byCode, array $evidence): array
    {
        $sets = [];
        $values = [];
        foreach ($children as $child) {
            $row = $byCode[$child] ?? null;
            $ev = $row !== null ? ($evidence[$row->productPublicId] ?? null) : null;
            $ids = $ev['specificationDefinitionIds'] ?? [];
            $sets[] = $ids;
            $values[] = $ev['specificationFingerprints'] ?? [];
        }
        if ($sets === []) {
            return [
                'sharedDefinitions' => 0,
                'childSpecificDefinitions' => 0,
                'commonValueDefinitions' => 0,
                'variableDefinitions' => 0,
            ];
        }
        $shared = $sets[0];
        $union = $sets[0];
        for ($i = 1, $n = count($sets); $i < $n; ++$i) {
            $shared = array_values(array_intersect($shared, $sets[$i]));
            $union = array_values(array_unique(array_merge($union, $sets[$i])));
        }
        $commonValue = 0;
        $variable = 0;
        foreach ($shared as $defId) {
            $fps = [];
            foreach ($values as $map) {
                if (isset($map[$defId])) {
                    $fps[] = $map[$defId];
                }
            }
            if ($fps !== [] && count(array_unique($fps)) === 1) {
                ++$commonValue;
            } else {
                ++$variable;
            }
        }

        return [
            'sharedDefinitions' => count($shared),
            'childSpecificDefinitions' => max(0, count($union) - count($shared)),
            'commonValueDefinitions' => $commonValue,
            'variableDefinitions' => $variable,
        ];
    }

    /**
     * @param list<string> $children
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @param array<string, array<string, mixed>> $evidence
     * @return array{label: string, common: int, distinct: int}
     */
    private function mediaShape(array $children, array $byCode, array $evidence): array
    {
        $sets = [];
        foreach ($children as $child) {
            $row = $byCode[$child] ?? null;
            $ev = $row !== null ? ($evidence[$row->productPublicId] ?? null) : null;
            $sets[] = $ev['mediaKeys'] ?? [];
        }
        $nonEmpty = array_values(array_filter($sets, static fn (array $s): bool => $s !== []));
        if ($nonEmpty === []) {
            return ['label' => 'NO_MEDIA_EVIDENCE', 'common' => 0, 'distinct' => 0];
        }
        $common = $nonEmpty[0];
        $union = $nonEmpty[0];
        for ($i = 1, $n = count($nonEmpty); $i < $n; ++$i) {
            $common = array_values(array_intersect($common, $nonEmpty[$i]));
            $union = array_values(array_unique(array_merge($union, $nonEmpty[$i])));
        }
        if ($common !== [] && count($common) === count($union)) {
            return ['label' => 'COMMON_MEDIA', 'common' => count($common), 'distinct' => 0];
        }
        if ($common !== []) {
            return ['label' => 'PARTIAL_COMMON_MEDIA', 'common' => count($common), 'distinct' => count($union) - count($common)];
        }

        return ['label' => 'DISTINCT_MEDIA', 'common' => 0, 'distinct' => count($union)];
    }

    /**
     * @param array<string, ProductStyleRecord> $stylesByCode
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @return array<string, mixed>|null
     */
    private function smokeBvaX(array $stylesByCode, array $byCode): ?array
    {
        $style = $stylesByCode[self::SMOKE_CHILD] ?? null;
        if ($style === null) {
            return null;
        }

        return [
            'parentStyleCode' => $style->parentStyleCode ?? self::SMOKE_PARENT,
            'childStyleCode' => self::SMOKE_CHILD,
            'decision' => 'REVIEW',
            'confidence' => 'SMOKE',
            'parentExists' => isset($stylesByCode[self::SMOKE_PARENT]),
            'childExists' => true,
            'productCount' => isset($byCode[self::SMOKE_CHILD]) ? 1 : 0,
            'productPublicId' => $byCode[self::SMOKE_CHILD]->productPublicId ?? null,
            'productName' => $byCode[self::SMOKE_CHILD]->productName ?? null,
            'currentParent' => $style->parentStyleCode,
            'expectedProductStyle' => self::SMOKE_CHILD,
            'currentAssignment' => $byCode[self::SMOKE_CHILD]->currentAssignedStyleCode ?? null,
            'reason' => 'Existing smoke/test relationship. Not operator-confirmed. Do not treat as a genuine family child.',
            'applicationState' => $style->parentStyleCode === self::SMOKE_PARENT ? 'ALREADY_PERSISTED' : 'NOT_APPLIED',
            'status' => 'NOT_APPLIED',
        ];
    }

    /**
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @param list<array<string, mixed>> $families
     * @param array<string, string> $claimedChildren
     * @param list<array<string, mixed>> $standalone
     * @return array<string, string>
     */
    private function styleRoles(array $byCode, array $families, array $claimedChildren, array $standalone): array
    {
        $roles = [];
        foreach ($families as $family) {
            if ($family['decision'] === 'REJECT') {
                continue;
            }
            $roles[$family['parentStyleCode']] = $family['decision'] === 'REVIEW' ? 'REVIEW' : 'ROOT_PARENT';
            foreach ($family['childStyleCodes'] as $child) {
                $roles[$child] = $family['decision'] === 'REVIEW' ? 'REVIEW' : 'CHILD';
            }
        }
        foreach ($standalone as $row) {
            $roles[$row['styleCode']] = 'STANDALONE';
        }
        foreach ($byCode as $code => $_) {
            $roles[$code] = $roles[$code] ?? 'STANDALONE';
        }
        unset($claimedChildren);

        return $roles;
    }

    /**
     * @param list<array<string, mixed>> $families
     * @param list<array<string, mixed>> $standalone
     * @param array<string, mixed>|null $smoke
     * @param array<string, ProductStyleRecord> $stylesByCode
     * @param array<string, ProductCodeStyleMappingRow> $byCode
     * @return list<array<string, mixed>>
     */
    private function proposedTree(
        array $families,
        array $standalone,
        ?array $smoke,
        array $stylesByCode,
        array $byCode,
    ): array {
        $nodes = [];
        foreach ($families as $family) {
            if ($family['decision'] === 'REJECT') {
                continue;
            }
            $children = [];
            foreach ($family['childStyleCodes'] as $child) {
                $childNode = [
                    'styleCode' => $child,
                    'role' => 'CHILD',
                    'decision' => $family['decision'],
                    'styleExists' => isset($stylesByCode[$child]),
                    'productMapped' => isset($byCode[$child]),
                    'applicationState' => $family['decision'] === 'OPERATOR_CONFIRMED'
                        && isset($stylesByCode[$child])
                        && ($stylesByCode[$child]->parentStyleCode === $family['parentStyleCode'])
                            ? 'ALREADY_PERSISTED'
                            : 'NOT_APPLIED',
                    'children' => [],
                ];
                if ($smoke !== null && $child === self::SMOKE_PARENT) {
                    $childNode['children'][] = [
                        'styleCode' => self::SMOKE_CHILD,
                        'role' => 'REVIEW',
                        'decision' => 'REVIEW',
                        'styleExists' => true,
                        'productMapped' => isset($byCode[self::SMOKE_CHILD]),
                        'applicationState' => $smoke['applicationState'],
                        'children' => [],
                    ];
                }
                $children[] = $childNode;
            }
            $nodes[] = [
                'styleCode' => $family['parentStyleCode'],
                'role' => 'ROOT_PARENT',
                'decision' => $family['decision'],
                'styleExists' => isset($stylesByCode[$family['parentStyleCode']]),
                'productMapped' => isset($byCode[$family['parentStyleCode']]),
                'applicationState' => 'NOT_APPLIED',
                'children' => $children,
            ];
        }
        foreach ($standalone as $row) {
            $nodes[] = [
                'styleCode' => $row['styleCode'],
                'role' => 'STANDALONE',
                'decision' => 'STANDALONE',
                'styleExists' => $row['styleExists'],
                'productMapped' => true,
                'applicationState' => 'NOT_APPLIED',
                'children' => [],
            ];
        }
        usort($nodes, static function (array $a, array $b): int {
            $rank = static function (array $node): int {
                return match ($node['decision']) {
                    'OPERATOR_CONFIRMED' => 0,
                    'APPROVE_CANDIDATE' => 1,
                    'REVIEW' => 2,
                    default => 3,
                };
            };

            return $rank($a) <=> $rank($b) ?: strcmp($a['styleCode'], $b['styleCode']);
        });

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<array<string, mixed>> $incoming
     */
    private function wouldCycle(array $existing, array $incoming): bool
    {
        $childToParent = [];
        foreach (array_merge($existing, $incoming) as $rel) {
            $childToParent[(string) $rel['childStyleCode']] = (string) $rel['parentStyleCode'];
        }

        return $this->codeGraphCycles($childToParent)['deepCycles'] > 0
            || $this->codeGraphCycles($childToParent)['selfCycles'] > 0
            || $this->codeGraphCycles($childToParent)['directCycles'] > 0;
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @param array<string, ProductStyleRecord> $stylesByCode
     * @return array{selfCycles: int, directCycles: int, deepCycles: int, rejected: list<string>}
     */
    private function validateCycles(array $relationships, array $stylesByCode): array
    {
        $childToParent = [];
        foreach ($stylesByCode as $style) {
            if ($style->parentStyleCode !== null) {
                $childToParent[$style->styleCode] = $style->parentStyleCode;
            }
        }
        foreach ($relationships as $rel) {
            if (in_array($rel['decision'] ?? '', ['OPERATOR_CONFIRMED', 'APPROVE_CANDIDATE'], true)) {
                $childToParent[(string) $rel['childStyleCode']] = (string) $rel['parentStyleCode'];
            }
        }
        foreach ($stylesByCode as $style) {
            if ($style->parentPublicId === null) {
                continue;
            }
            $parent = $stylesByCode[$style->parentStyleCode ?? ''] ?? null;
            $ancestors = [];
            if ($parent !== null) {
                $ancestors[] = $parent->publicId;
                foreach ($parent->ancestors as $ancestor) {
                    $ancestors[] = $ancestor['publicId'];
                }
            }
            try {
                $this->cycleGuard->assertCanSetParent($style->publicId, $style->parentPublicId, $ancestors);
            } catch (\Throwable) {
                // counted in code-graph walk
            }
        }

        return $this->codeGraphCycles($childToParent);
    }

    /**
     * @param array<string, string> $childToParent
     * @return array{selfCycles: int, directCycles: int, deepCycles: int, rejected: list<string>}
     */
    private function codeGraphCycles(array $childToParent): array
    {
        $self = 0;
        $direct = 0;
        $deep = 0;
        $rejected = [];
        foreach ($childToParent as $child => $parent) {
            if ($child === $parent) {
                ++$self;
                $rejected[] = $child . '→' . $parent;
                continue;
            }
            if (($childToParent[$parent] ?? null) === $child) {
                ++$direct;
                $rejected[] = $child . '→' . $parent;
                continue;
            }
            $seen = [$child => true];
            $walk = $parent;
            $depth = 0;
            while ($walk !== null && $depth < 64) {
                if (isset($seen[$walk])) {
                    ++$deep;
                    $rejected[] = $child . '→' . $parent;
                    break;
                }
                $seen[$walk] = true;
                $walk = $childToParent[$walk] ?? null;
                ++$depth;
            }
        }

        return [
            'selfCycles' => $self,
            'directCycles' => $direct,
            'deepCycles' => $deep,
            'rejected' => array_values(array_unique($rejected)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $families
     * @return list<array<string, mixed>>
     */
    private function flattenRelationships(array $families): array
    {
        $out = [];
        foreach ($families as $family) {
            foreach ($family['relationships'] as $rel) {
                $out[] = $rel;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function localInheritance(): array
    {
        return [
            'SPECIFICATIONS' => 'LOCAL',
            'IMAGES' => 'LOCAL',
            'DOCUMENTS' => 'LOCAL',
            'SPIN_360' => 'LOCAL',
            'MODELS_3D' => 'LOCAL',
            'AR_ASSETS' => 'LOCAL',
        ];
    }
}
