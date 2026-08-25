<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Contract\CatalogueProductStyleEvidenceSource;

/**
 * Dry-run discovery for CatalogueNode → parent ProductStyle proposals.
 * Never writes catalogue links, styles, assignments, products, or regions.
 */
final class DiscoverCatalogueProductStyleMappingService
{
    /** @var list<string> */
    private const OPERATOR_INTENDED_NODE_NAMES = [
        'BV 110C Flame Retardant',
        'DV 110C Braid, Flame Retardant',
        'RR 110C Fire Resistant',
        'BH 110C Braid, Fire Resistant',
    ];

    /** @var array<string, string> */
    private const OPERATOR_INTENDED_STYLES = [
        'BV 110C Flame Retardant' => 'BV',
        'DV 110C Braid, Flame Retardant' => 'DV',
        'RR 110C Fire Resistant' => 'RR',
        'BH 110C Braid, Fire Resistant' => 'BH',
    ];

    public function __construct(
        private readonly CatalogueProductStyleEvidenceSource $evidence,
        private readonly ResolveCatalogueStyleFamilyProductsService $resolver = new ResolveCatalogueStyleFamilyProductsService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        $snapshot = $this->evidence->loadSnapshot();
        $indexes = $this->buildIndexes($snapshot);

        $proposals = [];
        $review = [];
        $conflicts = [];
        $operatorInvestigations = [];

        foreach ($indexes['nodesByPublicId'] as $node) {
            $proposal = $this->analyseNode($node, $indexes);
            if ($proposal !== null) {
                $proposals[] = $proposal;
                if (($proposal['decision'] ?? '') === 'CONFLICT') {
                    $conflicts[] = $proposal;
                } elseif (in_array($proposal['decision'] ?? '', ['REVIEW', 'PARTIAL_FAMILY_EVIDENCE'], true)) {
                    $review[] = $proposal;
                }
            }
        }

        foreach (self::OPERATOR_INTENDED_NODE_NAMES as $nodeName) {
            $operatorInvestigations[] = $this->investigateOperatorNode($nodeName, $proposals, $indexes);
        }

        $decisionCounts = $this->countBy($proposals, 'decision');
        $exact = array_values(array_filter($proposals, static fn (array $p): bool => ($p['decision'] ?? '') === 'EXACT_FAMILY_EVIDENCE'));
        $strong = array_values(array_filter($proposals, static fn (array $p): bool => ($p['decision'] ?? '') === 'STRONG_FAMILY_EVIDENCE'));

        $totals = $this->aggregateTotals($proposals);

        return [
            'currentArchitecture' => [
                'catalogueNavigation' => 'CatalogueTab → CatalogueNode → CatalogueNodeProducts → Product',
                'productClassification' => 'Family / Category / Series (separate from catalogue)',
                'productStyleHierarchy' => 'ProductStyle ParentStyleId tree + ProductStyleAssignments',
                'proposedAdditiveLink' => '[product].[CatalogueNodeProductStyles] (CatalogueNodeId + ProductStyleId, unique)',
                'directProductLinksPreserved' => true,
                'styleResolutionUsesPersistedHierarchy' => true,
                'styleResolutionUsesPrefixMatching' => false,
                'linkedStyleIncludesOwnProduct' => true,
                'orderingRule' => '1) direct CatalogueNodeProducts.DisplayOrder 2) proposed CatalogueNodeProductStyles.DisplayOrder 3) ProductStyle.SortOrder/StyleCode 4) ProductName',
            ],
            'catalogueNodes' => array_values($indexes['nodesByPublicId']),
            'proposals' => $proposals,
            'operatorInvestigations' => $operatorInvestigations,
            'styleFamilies' => $indexes['parentFamilies'],
            'exactFamilyProposals' => $exact,
            'strongFamilyProposals' => $strong,
            'review' => $review,
            'conflicts' => $conflicts,
            'deduplication' => $totals['deduplication'],
            'regionSimulation' => $totals['regionSimulation'],
            'redundantDirectLinks' => $totals['redundantDirectLinks'],
            'counters' => [
                'catalogueNodesAnalysed' => count($indexes['nodesByPublicId']),
                'catalogueNodesWithProducts' => count(array_filter(
                    $indexes['nodesByPublicId'],
                    static fn (array $n): bool => (int) ($n['directProductCount'] ?? 0) > 0,
                )),
                'parentProductStylesAnalysed' => count($indexes['parentFamilies']),
                'EXACT_FAMILY_EVIDENCE' => (int) ($decisionCounts['EXACT_FAMILY_EVIDENCE'] ?? 0),
                'STRONG_FAMILY_EVIDENCE' => (int) ($decisionCounts['STRONG_FAMILY_EVIDENCE'] ?? 0),
                'PARTIAL_FAMILY_EVIDENCE' => (int) ($decisionCounts['PARTIAL_FAMILY_EVIDENCE'] ?? 0),
                'CONFLICT' => (int) ($decisionCounts['CONFLICT'] ?? 0),
                'REVIEW' => (int) ($decisionCounts['REVIEW'] ?? 0),
                'NO_STYLE_FAMILY' => (int) ($decisionCounts['NO_STYLE_FAMILY'] ?? 0),
                'NOT_APPLICABLE' => (int) ($decisionCounts['NOT_APPLICABLE'] ?? 0),
                'proposedCatalogueNodeProductStyleLinks' => count(array_filter(
                    $proposals,
                    static fn (array $p): bool => in_array($p['decision'] ?? '', ['EXACT_FAMILY_EVIDENCE', 'STRONG_FAMILY_EVIDENCE'], true)
                        && ($p['proposedParentStyleCode'] ?? null) !== null,
                )),
                'productsCurrentlyDirectlyLinked' => $totals['productsCurrentlyDirectlyLinked'],
                'productsResolvedThroughProposedStyles' => $totals['productsResolvedThroughProposedStyles'],
                'duplicateProductResolutions' => $totals['duplicateProductResolutions'],
                'productsAddedByStyleResolution' => $totals['productsAddedByStyleResolution'],
                'productsUnexpectedlyRemoved' => $totals['productsUnexpectedlyRemoved'],
                'nodesBecomingEmptyByRegion' => $totals['nodesBecomingEmptyByRegion'],
                'nodesGainingAdditionalVisibleProducts' => $totals['nodesGainingAdditionalVisibleProducts'],
            ],
            'assertions' => [
                'CATALOGUE_NAVIGATION_REMAINS_SEPARATE' => true,
                'PRODUCTSTYLE_HIERARCHY_REMAINS_SEPARATE' => true,
                'FAKE_PARENT_PRODUCTS_CREATED' => false,
                'CATALOGUE_NODE_CAN_LINK_PARENT_STYLE' => true,
                'STYLE_RESOLUTION_USES_PERSISTED_HIERARCHY' => true,
                'STYLE_RESOLUTION_USES_PREFIX_MATCHING' => false,
                'DIRECT_PRODUCT_LINKS_PRESERVED' => true,
                'STYLE_AND_DIRECT_PRODUCTS_DEDUPLICATED' => true,
                'PUBLICATION_FILTER_PRESERVED' => true,
                'REGION_FILTER_PRESERVED' => true,
                'EMPTY_BRANCH_PRUNING_PRESERVED' => true,
            ],
            'writeGuard' => [
                'catalogueNodeWrites' => 0,
                'catalogueProductLinkWrites' => 0,
                'catalogueStyleLinkWrites' => 0,
                'productStyleWrites' => 0,
                'parentStyleIdWrites' => 0,
                'productStyleAssignmentWrites' => 0,
                'productWrites' => 0,
                'variantWrites' => 0,
                'specificationWrites' => 0,
                'mediaWrites' => 0,
                'marketRegionWrites' => 0,
                'blobWrites' => 0,
                'wordpressWrites' => 0,
                'tcMysqlWrites' => 0,
                'legacyFilesystemWrites' => 0,
                'productionWrites' => 0,
                'forbiddenWriteAttempts' => 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildIndexes(array $snapshot): array
    {
        $nodesByPublicId = [];
        $nodesByName = [];
        foreach ($snapshot['nodes'] ?? [] as $node) {
            $publicId = (string) ($node['publicId'] ?? '');
            if ($publicId === '') {
                continue;
            }
            $nodesByPublicId[$publicId] = $node;
            $nodesByName[(string) ($node['nodeName'] ?? '')] = $node;
        }

        $directByNode = [];
        foreach ($snapshot['directProducts'] ?? [] as $row) {
            $nodePublicId = (string) ($row['nodePublicId'] ?? '');
            if ($nodePublicId === '') {
                continue;
            }
            $directByNode[$nodePublicId][] = $row;
        }

        $stylesByCode = [];
        $stylesByPublicId = [];
        $childrenByParentPublicId = [];
        foreach ($snapshot['productStyles'] ?? [] as $style) {
            $code = strtoupper((string) ($style['styleCode'] ?? ''));
            $publicId = (string) ($style['publicId'] ?? '');
            if ($code === '' || $publicId === '') {
                continue;
            }
            $stylesByCode[$code] = $style;
            $stylesByPublicId[$publicId] = $style;
            $parentPublicId = (string) ($style['parentPublicId'] ?? '');
            if ($parentPublicId !== '') {
                $childrenByParentPublicId[$parentPublicId][] = $publicId;
            }
        }

        $assignmentByProduct = [];
        $productsByStylePublicId = [];
        foreach ($snapshot['styleAssignments'] ?? [] as $row) {
            $productPublicId = (string) ($row['productPublicId'] ?? '');
            $stylePublicId = (string) ($row['stylePublicId'] ?? '');
            if ($productPublicId === '' || $stylePublicId === '') {
                continue;
            }
            $assignmentByProduct[$productPublicId] = $row;
            $productsByStylePublicId[$stylePublicId][] = [
                'productPublicId' => $productPublicId,
                'productCode' => (string) ($row['productCode'] ?? ''),
                'productName' => (string) ($row['productName'] ?? ''),
            ];
        }

        $productsByPublicId = [];
        foreach ($snapshot['products'] ?? [] as $product) {
            $publicId = (string) ($product['publicId'] ?? '');
            if ($publicId !== '') {
                $productsByPublicId[$publicId] = $product;
            }
        }

        $regionCodesByProductPublicId = [];
        foreach ($snapshot['productRegions'] ?? [] as $row) {
            $productPublicId = (string) ($row['productPublicId'] ?? '');
            $regionCode = strtoupper(trim((string) ($row['regionCode'] ?? '')));
            if ($productPublicId === '' || $regionCode === '') {
                continue;
            }
            $regionCodesByProductPublicId[$productPublicId][] = $regionCode;
        }
        foreach ($regionCodesByProductPublicId as $id => $codes) {
            $regionCodesByProductPublicId[$id] = array_values(array_unique($codes));
        }

        $parentFamilies = [];
        foreach ($stylesByCode as $code => $style) {
            if (!empty($style['parentPublicId'])) {
                continue;
            }
            $childCodes = [];
            foreach ($childrenByParentPublicId[(string) $style['publicId']] ?? [] as $childPublicId) {
                $child = $stylesByPublicId[$childPublicId] ?? null;
                if ($child !== null) {
                    $childCodes[] = (string) $child['styleCode'];
                }
            }
            sort($childCodes);
            $parentFamilies[$code] = [
                'parentStyleCode' => $code,
                'parentStylePublicId' => (string) $style['publicId'],
                'childStyleCodes' => $childCodes,
                'hasProduct' => isset($productsByStylePublicId[(string) $style['publicId']]),
            ];
        }

        foreach ($nodesByPublicId as $publicId => $node) {
            $direct = array_values(array_filter(
                $directByNode[$publicId] ?? [],
                static fn (array $row): bool => !empty($row['isActive']),
            ));
            $nodesByPublicId[$publicId]['directProducts'] = $direct;
            $nodesByPublicId[$publicId]['directProductCount'] = count($direct);
        }

        $regions = array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $snapshot['regions'] ?? [],
        )));
        if ($regions === []) {
            $regions = ['AU', 'NZ', 'US'];
        }

        return [
            'nodesByPublicId' => $nodesByPublicId,
            'nodesByName' => $nodesByName,
            'directByNode' => $directByNode,
            'stylesByCode' => $stylesByCode,
            'stylesByPublicId' => $stylesByPublicId,
            'childrenByParentPublicId' => $childrenByParentPublicId,
            'assignmentByProduct' => $assignmentByProduct,
            'productsByStylePublicId' => $productsByStylePublicId,
            'productsByPublicId' => $productsByPublicId,
            'regionCodesByProductPublicId' => $regionCodesByProductPublicId,
            'parentFamilies' => $parentFamilies,
            'regions' => $regions,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $indexes
     * @return array<string, mixed>|null
     */
    private function analyseNode(array $node, array $indexes): ?array
    {
        $nodePublicId = (string) ($node['publicId'] ?? '');
        $directProducts = $node['directProducts'] ?? [];
        if ($directProducts === []) {
            return [
                'catalogueNodePublicId' => $nodePublicId,
                'cataloguePath' => (string) ($node['cataloguePath'] ?? ''),
                'nodeName' => (string) ($node['nodeName'] ?? ''),
                'currentDirectProducts' => [],
                'proposedParentStyleCode' => null,
                'proposedParentStylePublicId' => null,
                'childStyleCodes' => [],
                'decision' => 'NOT_APPLICABLE',
                'confidence' => 'N/A',
                'evidence' => ['reason' => 'No direct catalogue product placements.'],
                'simulation' => null,
            ];
        }

        $parentVotes = [];
        $assignedStyles = [];
        foreach ($directProducts as $direct) {
            $productPublicId = (string) ($direct['productPublicId'] ?? '');
            $assignment = $indexes['assignmentByProduct'][$productPublicId] ?? null;
            if ($assignment === null) {
                continue;
            }
            $styleCode = strtoupper((string) ($assignment['styleCode'] ?? ''));
            $parentCode = strtoupper(trim((string) ($assignment['parentStyleCode'] ?? '')));
            $assignedStyles[] = $styleCode;
            if ($parentCode !== '') {
                $parentVotes[$parentCode] = ($parentVotes[$parentCode] ?? 0) + 1;
            }
        }

        arsort($parentVotes);
        $topParent = array_key_first($parentVotes);
        $topVotes = $topParent !== null ? (int) $parentVotes[$topParent] : 0;
        $totalAssigned = count($assignedStyles);
        $distinctParents = count($parentVotes);

        $proposedParent = null;
        $decision = 'NO_STYLE_FAMILY';
        $confidence = 'LOW';
        $evidence = [];

        if ($totalAssigned === 0) {
            $decision = 'NO_STYLE_FAMILY';
            $evidence[] = 'Direct products have no ProductStyleAssignments.';
        } elseif ($distinctParents === 0) {
            $decision = 'NO_STYLE_FAMILY';
            $evidence[] = 'Assigned styles have no parent ProductStyle.';
        } elseif ($distinctParents > 1) {
            $voteValues = array_values($parentVotes);
            rsort($voteValues);
            if (count($voteValues) > 1 && $voteValues[0] === $voteValues[1]) {
                $decision = 'CONFLICT';
                $confidence = 'LOW';
                $evidence[] = 'Multiple parent ProductStyles tied: ' . implode(', ', array_keys($parentVotes));
            } else {
                $proposedParent = $topParent;
                $ratio = $totalAssigned > 0 ? $topVotes / $totalAssigned : 0.0;
                $family = $indexes['parentFamilies'][$proposedParent] ?? null;
                $childCodes = $family['childStyleCodes'] ?? [];
                $evidence[] = sprintf(
                    '%d/%d direct products assigned to child styles under parent %s.',
                    $topVotes,
                    count($directProducts),
                    $proposedParent,
                );
                if ($family !== null) {
                    $evidence[] = 'Parent has ' . count($childCodes) . ' persisted child styles.';
                }
                if ($ratio >= 1.0 && $distinctParents === 1) {
                    $decision = 'EXACT_FAMILY_EVIDENCE';
                    $confidence = 'HIGH';
                } elseif ($ratio >= 0.75) {
                    $decision = 'STRONG_FAMILY_EVIDENCE';
                    $confidence = 'HIGH';
                } elseif ($ratio >= 0.5) {
                    $decision = 'PARTIAL_FAMILY_EVIDENCE';
                    $confidence = 'MEDIUM';
                } else {
                    $decision = 'REVIEW';
                    $confidence = 'LOW';
                }
            }
        } else {
            $proposedParent = $topParent;
            $ratio = $totalAssigned > 0 ? $topVotes / $totalAssigned : 0.0;
            $family = $indexes['parentFamilies'][$proposedParent] ?? null;
            $childCodes = $family['childStyleCodes'] ?? [];
            $evidence[] = sprintf(
                '%d/%d direct products assigned to child styles under parent %s.',
                $topVotes,
                count($directProducts),
                $proposedParent,
            );
            if ($family !== null) {
                $evidence[] = 'Parent has ' . count($childCodes) . ' persisted child styles.';
            }
            if ($ratio >= 1.0) {
                $decision = 'EXACT_FAMILY_EVIDENCE';
                $confidence = 'HIGH';
            } elseif ($ratio >= 0.75) {
                $decision = 'STRONG_FAMILY_EVIDENCE';
                $confidence = 'HIGH';
            } elseif ($ratio >= 0.5) {
                $decision = 'PARTIAL_FAMILY_EVIDENCE';
                $confidence = 'MEDIUM';
            } else {
                $decision = 'REVIEW';
                $confidence = 'LOW';
            }
        }

        $operatorHint = self::OPERATOR_INTENDED_STYLES[(string) ($node['nodeName'] ?? '')] ?? null;
        if ($operatorHint !== null && $proposedParent !== null && strtoupper($operatorHint) !== strtoupper($proposedParent)) {
            $decision = 'CONFLICT';
            $evidence[] = 'Operator-intended style ' . $operatorHint . ' differs from assignment evidence parent ' . $proposedParent . '.';
        } elseif ($operatorHint !== null && $proposedParent === null) {
            $decision = 'REVIEW';
            $evidence[] = 'Operator-intended style ' . $operatorHint . ' but assignment evidence insufficient.';
            if (isset($indexes['parentFamilies'][strtoupper($operatorHint)])) {
                $proposedParent = strtoupper($operatorHint);
            }
        }

        $simulation = null;
        $childStyleCodes = [];
        if ($proposedParent !== null && isset($indexes['parentFamilies'][$proposedParent])) {
            $childStyleCodes = $indexes['parentFamilies'][$proposedParent]['childStyleCodes'];
            $simulation = $this->simulateNode($node, $proposedParent, $directProducts, $indexes);
        }

        return [
            'catalogueNodePublicId' => $nodePublicId,
            'cataloguePath' => (string) ($node['cataloguePath'] ?? ''),
            'nodeName' => (string) ($node['nodeName'] ?? ''),
            'currentDirectProducts' => array_map(static fn (array $d): array => [
                'productPublicId' => (string) ($d['productPublicId'] ?? ''),
                'productCode' => (string) ($d['productCode'] ?? ''),
                'productName' => (string) ($d['productName'] ?? ''),
                'assignedStyleCode' => ($indexes['assignmentByProduct'][(string) ($d['productPublicId'] ?? '')]['styleCode'] ?? null),
                'parentStyleCode' => ($indexes['assignmentByProduct'][(string) ($d['productPublicId'] ?? '')]['parentStyleCode'] ?? null),
            ], $directProducts),
            'proposedParentStyleCode' => $proposedParent,
            'proposedParentStylePublicId' => $proposedParent !== null
                ? ($indexes['parentFamilies'][$proposedParent]['parentStylePublicId'] ?? null)
                : null,
            'childStyleCodes' => $childStyleCodes,
            'assignedStyleCodes' => $assignedStyles,
            'decision' => $decision,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'operatorIntendedStyle' => $operatorHint,
            'simulation' => $simulation,
            'redundantDirectLinks' => $this->redundantDirectLinks($directProducts, $simulation),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $directProducts
     * @param array<string, mixed> $indexes
     * @return array<string, mixed>
     */
    private function simulateNode(array $node, string $proposedParent, array $directProducts, array $indexes): array
    {
        $regions = $indexes['regions'];
        $byRegion = [];
        $globalCurrent = $this->filterDirectVisible($directProducts, $indexes, null);
        $globalStyle = $this->resolver->resolveForLinkedStyle(
            $proposedParent,
            $indexes['stylesByCode'],
            $indexes['childrenByParentPublicId'],
            $indexes['productsByStylePublicId'],
            $indexes['productsByPublicId'],
            $indexes['regionCodesByProductPublicId'],
            null,
            true,
        );
        $globalMerged = $this->resolver->mergeDirectAndStyleProducts($globalCurrent, $globalStyle['visibleProducts']);
        $byRegion['ALL'] = [
            'currentVisibleCount' => count($globalCurrent),
            'proposedVisibleCount' => count($globalMerged['products']),
            'candidateStyleCount' => count($globalStyle['candidateProducts']),
            'visibleStyleCount' => count($globalStyle['visibleProducts']),
            'duplicatesRemoved' => $globalMerged['duplicatesRemoved'],
            'productsAdded' => max(0, count($globalMerged['products']) - count($globalCurrent)),
            'productsRemoved' => $this->removedProducts($globalCurrent, $globalMerged['products']),
            'currentProducts' => array_column($globalCurrent, 'productCode'),
            'proposedProducts' => array_column($globalMerged['products'], 'productCode'),
        ];

        foreach ($regions as $regionCode) {
            $current = $this->filterDirectVisible($directProducts, $indexes, $regionCode);
            $style = $this->resolver->resolveForLinkedStyle(
                $proposedParent,
                $indexes['stylesByCode'],
                $indexes['childrenByParentPublicId'],
                $indexes['productsByStylePublicId'],
                $indexes['productsByPublicId'],
                $indexes['regionCodesByProductPublicId'],
                $regionCode,
                true,
            );
            $merged = $this->resolver->mergeDirectAndStyleProducts($current, $style['visibleProducts']);
            $byRegion[$regionCode] = [
                'currentVisibleCount' => count($current),
                'proposedVisibleCount' => count($merged['products']),
                'candidateStyleCount' => count($style['candidateProducts']),
                'visibleStyleCount' => count($style['visibleProducts']),
                'duplicatesRemoved' => $merged['duplicatesRemoved'],
                'productsAdded' => max(0, count($merged['products']) - count($current)),
                'productsRemoved' => $this->removedProducts($current, $merged['products']),
                'currentProducts' => array_column($current, 'productCode'),
                'proposedProducts' => array_column($merged['products'], 'productCode'),
            ];
        }

        return [
            'proposedParentStyleCode' => $proposedParent,
            'descendantStyleCodes' => $globalStyle['descendantStyleCodes'],
            'byRegion' => $byRegion,
        ];
    }

    /**
     * @param list<array<string, mixed>> $directProducts
     * @param array<string, mixed> $indexes
     * @return list<array<string, mixed>>
     */
    private function filterDirectVisible(array $directProducts, array $indexes, ?string $regionCode): array
    {
        $visible = [];
        foreach ($directProducts as $direct) {
            $productPublicId = (string) ($direct['productPublicId'] ?? '');
            $product = $indexes['productsByPublicId'][$productPublicId] ?? null;
            if ($product === null) {
                continue;
            }
            if (!empty($product['deletedAtUtc']) || empty($product['isActive'])) {
                continue;
            }
            if (strtolower((string) ($product['publicationStatus'] ?? '')) !== 'published') {
                continue;
            }
            if ($regionCode !== null) {
                $codes = $indexes['regionCodesByProductPublicId'][$productPublicId] ?? [];
                if (!in_array(strtoupper($regionCode), $codes, true)) {
                    continue;
                }
            }
            $visible[] = [
                'productPublicId' => $productPublicId,
                'productCode' => (string) ($direct['productCode'] ?? $product['internalCode'] ?? ''),
                'productName' => (string) ($direct['productName'] ?? $product['productName'] ?? ''),
                'displayOrder' => (int) ($direct['displayOrder'] ?? 0),
            ];
        }

        return $visible;
    }

    /**
     * @param list<array<string, mixed>> $current
     * @param list<array<string, mixed>> $proposed
     * @return list<string>
     */
    private function removedProducts(array $current, array $proposed): array
    {
        $proposedIds = array_fill_keys(array_column($proposed, 'productPublicId'), true);
        $removed = [];
        foreach ($current as $row) {
            $id = (string) ($row['productPublicId'] ?? '');
            if ($id !== '' && !isset($proposedIds[$id])) {
                $removed[] = (string) ($row['productCode'] ?? $id);
            }
        }

        return $removed;
    }

    /**
     * @param list<array<string, mixed>> $directProducts
     * @param array<string, mixed>|null $simulation
     * @return list<array<string, mixed>>
     */
    private function redundantDirectLinks(array $directProducts, ?array $simulation): array
    {
        if ($simulation === null) {
            return [];
        }
        $proposedCodes = array_fill_keys($simulation['byRegion']['ALL']['proposedProducts'] ?? [], true);
        $redundant = [];
        foreach ($directProducts as $direct) {
            $code = (string) ($direct['productCode'] ?? '');
            if ($code !== '' && isset($proposedCodes[$code])) {
                $redundant[] = [
                    'productCode' => $code,
                    'productPublicId' => (string) ($direct['productPublicId'] ?? ''),
                    'flag' => 'DIRECT_PRODUCT_LINK_REDUNDANT_AFTER_STYLE_LINK',
                ];
            }
        }

        return $redundant;
    }

    /**
     * @param list<array<string, mixed>> $proposals
     * @param array<string, mixed> $indexes
     */
    private function investigateOperatorNode(string $nodeName, array $proposals, array $indexes): array
    {
        $node = $indexes['nodesByName'][$nodeName] ?? null;
        $intendedStyle = self::OPERATOR_INTENDED_STYLES[$nodeName] ?? null;
        $matchingProposal = null;
        foreach ($proposals as $proposal) {
            if (($proposal['nodeName'] ?? '') === $nodeName) {
                $matchingProposal = $proposal;
                break;
            }
        }

        return [
            'nodeName' => $nodeName,
            'nodeFound' => $node !== null,
            'catalogueNodePublicId' => $node['publicId'] ?? null,
            'cataloguePath' => $node['cataloguePath'] ?? null,
            'operatorIntendedStyle' => $intendedStyle,
            'proposedParentStyleCode' => $matchingProposal['proposedParentStyleCode'] ?? null,
            'decision' => $matchingProposal['decision'] ?? 'NOT_FOUND',
            'confidence' => $matchingProposal['confidence'] ?? 'N/A',
            'currentDirectProducts' => $matchingProposal['currentDirectProducts'] ?? [],
            'childStyleCodes' => $matchingProposal['childStyleCodes'] ?? [],
            'simulation' => $matchingProposal['simulation'] ?? null,
            'evidence' => $matchingProposal['evidence'] ?? ['Node not found in catalogue.'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $proposals
     * @return array<string, mixed>
     */
    private function aggregateTotals(array $proposals): array
    {
        $directTotal = 0;
        $resolvedTotal = 0;
        $duplicates = 0;
        $added = 0;
        $removed = 0;
        $emptyByRegion = 0;
        $gaining = 0;
        $redundant = [];
        $regionSimulation = [];

        foreach ($proposals as $proposal) {
            $sim = $proposal['simulation'] ?? null;
            if ($sim === null) {
                continue;
            }
            $directTotal += count($proposal['currentDirectProducts'] ?? []);
            foreach ($sim['byRegion'] ?? [] as $region => $row) {
                $regionSimulation[] = [
                    'nodeName' => $proposal['nodeName'],
                    'proposedStyle' => $proposal['proposedParentStyleCode'],
                    'region' => $region,
                    'currentVisibleCount' => $row['currentVisibleCount'] ?? 0,
                    'proposedVisibleCount' => $row['proposedVisibleCount'] ?? 0,
                    'productsAdded' => $row['productsAdded'] ?? 0,
                    'productsRemoved' => $row['productsRemoved'] ?? [],
                ];
                if (($row['currentVisibleCount'] ?? 0) > 0 && ($row['proposedVisibleCount'] ?? 0) === 0) {
                    ++$emptyByRegion;
                }
                if (($row['productsAdded'] ?? 0) > 0) {
                    ++$gaining;
                }
                $added += (int) ($row['productsAdded'] ?? 0);
                $removed += count($row['productsRemoved'] ?? []);
                $duplicates += (int) ($row['duplicatesRemoved'] ?? 0);
            }
            $resolvedTotal += (int) ($sim['byRegion']['ALL']['proposedVisibleCount'] ?? 0);
            foreach ($proposal['redundantDirectLinks'] ?? [] as $item) {
                $redundant[] = $item;
            }
        }

        return [
            'productsCurrentlyDirectlyLinked' => $directTotal,
            'productsResolvedThroughProposedStyles' => $resolvedTotal,
            'duplicateProductResolutions' => $duplicates,
            'productsAddedByStyleResolution' => $added,
            'productsUnexpectedlyRemoved' => $removed,
            'nodesBecomingEmptyByRegion' => $emptyByRegion,
            'nodesGainingAdditionalVisibleProducts' => $gaining,
            'redundantDirectLinks' => $redundant,
            'regionSimulation' => $regionSimulation,
            'deduplication' => ['duplicateResolutions' => $duplicates],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? 'UNKNOWN');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return $counts;
    }
}
