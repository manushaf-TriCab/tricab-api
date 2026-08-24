<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Application\DTO\CatalogueNavigationDryRunReport;
use TriCab\Core\Product\Migration\CatalogueNavigation\CatalogueNavigationDecision;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Additive-only Catalogue Navigation resolver.
 *
 * Never creates/renames/reorders/deletes CatalogueTabs or CatalogueNodes.
 * Never treats ProductCategory / Family / Series as catalogue nodes.
 * Never writes WordPress, tc, or Azure.
 */
final class ResolveCatalogueNavigationAdditiveMappingService
{
    /**
     * @param array<string, mixed> $input
     */
    public function dryRun(array $input): CatalogueNavigationDryRunReport
    {
        $forbidden = MigrationPayload::int($input['forbiddenWriteAttempts'] ?? 0);
        $target = MigrationPayload::assoc($input['target'] ?? []);
        $tabs = $this->records($target['tabs'] ?? []);
        $nodes = $this->records($target['nodes'] ?? []);
        $existingLinks = $this->records($target['productNodeLinks'] ?? []);
        $explicitMaps = $this->records($input['explicitMaps'] ?? []);
        $products = $this->records($input['legacyProducts'] ?? []);

        $nodeById = $this->index($nodes, 'publicId', false);
        $nodeByCode = $this->index($nodes, 'nodeCode', true);
        $pathIndex = $this->pathIndex($nodes, $tabs);
        $protectedLinks = $this->markProtected($existingLinks);

        $resolutions = [];
        $ignoredCategorySignals = [];
        foreach ($products as $product) {
            $built = $this->resolveProduct(
                $product,
                $nodes,
                $nodeById,
                $nodeByCode,
                $pathIndex,
                $tabs,
                $protectedLinks,
                $explicitMaps,
            );
            foreach ($built['resolutions'] as $row) {
                $resolutions[] = $row;
            }
            foreach ($built['ignoredProductCategorySignals'] as $ignored) {
                $ignoredCategorySignals[] = $ignored;
            }
        }

        usort(
            $resolutions,
            static fn (array $a, array $b): int => [$a['styleCode'], $a['legacyPath'], $a['targetNodeCode'] ?? '']
                <=> [$b['styleCode'], $b['legacyPath'], $b['targetNodeCode'] ?? ''],
        );

        $decisionCounts = $this->countBy($resolutions, 'decision');
        $actionCounts = $this->countBy($resolutions, 'projectedAction');
        $inserts = array_values(array_filter(
            $resolutions,
            static fn (array $row): bool => $row['projectedAction'] === CatalogueNavigationDecision::ACTION_INSERT,
        ));
        $identical = array_values(array_filter(
            $resolutions,
            static fn (array $row): bool => $row['decision'] === CatalogueNavigationDecision::EXISTING_IDENTICAL,
        ));
        $reviews = array_values(array_filter(
            $resolutions,
            static fn (array $row): bool => $row['projectedAction'] === CatalogueNavigationDecision::ACTION_REVIEW,
        ));
        $rollback = [];
        foreach ($inserts as $insert) {
            $rollback[] = [
                'relationshipKey' => $insert['projectedRelationshipKey'],
                'productPublicId' => $insert['productPublicId'],
                'styleCode' => $insert['styleCode'],
                'catalogueNodePublicId' => $insert['targetNodePublicId'],
                'catalogueNodeCode' => $insert['targetNodeCode'],
                'migrationBatchId' => $insert['migrationBatchId'],
                'ownership' => CatalogueNavigationDecision::OWNERSHIP_MIGRATION_CREATED,
                'rollbackEligible' => true,
            ];
        }

        $payload = [
            'phase' => '12.8-catalogue-navigation-additive-mapping',
            'banner' => 'CATALOGUE NAVIGATION — ADDITIVE ONLY. EXISTING STRUCTURE AND LINKS ARE PROTECTED.',
            'mode' => 'READ + RESOLVE + PROJECT',
            'applied' => false,
            'architecturalRule' => [
                'internalClassification' => 'Family → Category → Series → Product',
                'publicCatalogueNavigation' => 'CatalogueTab → CatalogueNode → Product',
                'productCategoryEqualsCatalogueNode' => false,
                'deriveNavigationFromProductCategory' => false,
            ],
            'protection' => [
                'catalogueTabsMustNotChange' => true,
                'catalogueNodesMustNotChange' => true,
                'existingProductNodeLinksMustNotChange' => true,
                'existingProductNodeLinksMustNotDelete' => true,
                'existingHierarchyMustNotChange' => true,
                'existingOrderingMustNotChange' => true,
                'regionMustNotDuplicateTree' => true,
            ],
            'targetSnapshot' => [
                'tabCount' => count($tabs),
                'nodeCount' => count($nodes),
                'existingProductNodeLinkCount' => count($existingLinks),
                'protectedLinkCount' => count($protectedLinks),
                'tabs' => $tabs,
                'nodes' => $nodes,
            ],
            'protectedExistingLinks' => $protectedLinks,
            'explicitMaps' => $explicitMaps,
            'ignoredProductCategorySignals' => $ignoredCategorySignals,
            'decisionCounts' => $decisionCounts,
            'actionCounts' => $actionCounts,
            'resolutions' => $resolutions,
            'projectedMutations' => [
                'catalogueTabWrites' => 0,
                'catalogueNodeWrites' => 0,
                'productNodeInserts' => count($inserts),
                'productNodeNoops' => count($identical),
                'productNodeReviews' => count($reviews),
                'productNodeDeletes' => 0,
                'productNodeMoves' => 0,
            ],
            'insertProjections' => $inserts,
            'identicalProtected' => $identical,
            'reviewItems' => $reviews,
            'rollbackProjection' => [
                'rule' => 'Rollback may remove only MIGRATION_CREATED relationships for this batch. PRE_EXISTING_PROTECTED is never deleted.',
                'eligible' => $rollback,
                'preservedProtectedCount' => count($protectedLinks),
            ],
            'publicationSafety' => [
                'creatingCatalogueLinkPublishesProduct' => false,
                'draftProductsRemainInvisibleOnPublicCatalogue' => true,
            ],
            'f12' => $this->f12Section($resolutions, $input),
            'legacySource' => $input['legacySource'] ?? [
                'wordpressConnected' => false,
                'taxonomySources' => [],
            ],
            'safety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => $forbidden,
                'targetWrites' => 0,
                'catalogueTabWrites' => 0,
                'catalogueNodeWrites' => 0,
                'productNodeWrites' => 0,
            ],
        ];

        return new CatalogueNavigationDryRunReport(
            mode: 'READ + RESOLVE + PROJECT',
            applied: false,
            wordpressWrites: 0,
            tcWrites: 0,
            forbiddenWriteAttempts: $forbidden,
            targetWrites: 0,
            catalogueTabWrites: 0,
            catalogueNodeWrites: 0,
            productNodeWrites: 0,
            payload: $payload,
        );
    }

    /**
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $nodes
     * @param array<string, array<string, mixed>> $nodeById
     * @param array<string, array<string, mixed>> $nodeByCode
     * @param array<string, list<array<string, mixed>>> $pathIndex
     * @param list<array<string, mixed>> $tabs
     * @param list<array<string, mixed>> $protectedLinks
     * @param list<array<string, mixed>> $explicitMaps
     * @return array{resolutions: list<array<string, mixed>>, ignoredProductCategorySignals: list<array<string, mixed>>}
     */
    private function resolveProduct(
        array $product,
        array $nodes,
        array $nodeById,
        array $nodeByCode,
        array $pathIndex,
        array $tabs,
        array $protectedLinks,
        array $explicitMaps,
    ): array {
        $style = strtoupper(trim(MigrationPayload::string($product['styleCode'] ?? '')));
        $ignored = [];
        $relationships = $this->records($product['catalogueRelationships'] ?? []);
        $usable = [];
        foreach ($relationships as $rel) {
            $kind = MigrationPayload::string($rel['sourceKind'] ?? '');
            if (in_array($kind, ['product_category', 'product_category_classification', 'family', 'series'], true)) {
                $ignored[] = [
                    'styleCode' => $style,
                    'sourceKind' => $kind,
                    'code' => $rel['legacyTermSlug'] ?? $rel['legacyPath'] ?? null,
                    'reason' => CatalogueNavigationDecision::EVIDENCE_PRODUCT_CATEGORY_REJECTED,
                ];
                continue;
            }
            if (($rel['regionDuplicatesTree'] ?? false) === true) {
                $ignored[] = [
                    'styleCode' => $style,
                    'sourceKind' => $kind,
                    'code' => $rel['legacyPath'] ?? null,
                    'reason' => CatalogueNavigationDecision::EVIDENCE_REGION_TREE_REJECTED,
                ];
                continue;
            }
            $usable[] = $rel;
        }

        if ($usable === []) {
            return [
                'resolutions' => [[
                    'styleCode' => $style,
                    'legacyProductName' => $product['legacyProductName'] ?? null,
                    'wordpressPostId' => $product['wordpressPostId'] ?? null,
                    'productPublicId' => $product['productPublicId'] ?? null,
                    'legacyPath' => null,
                    'legacyTaxonomy' => null,
                    'legacyTermId' => null,
                    'targetTabCode' => null,
                    'targetTabName' => null,
                    'targetNodeCode' => null,
                    'targetNodeName' => null,
                    'targetNodePublicId' => null,
                    'existingLink' => false,
                    'existingLinkOwnership' => null,
                    'decision' => CatalogueNavigationDecision::NOT_APPLICABLE,
                    'confidence' => CatalogueNavigationDecision::CONFIDENCE_NONE,
                    'projectedAction' => CatalogueNavigationDecision::ACTION_NOOP,
                    'migrationAction' => 'NOT_APPLICABLE',
                    'ownership' => null,
                    'migrationBatchId' => null,
                    'projectedRelationshipKey' => null,
                    'evidence' => $ignored !== []
                        ? array_column($ignored, 'reason')
                        : ['no_legacy_catalogue_navigation_evidence'],
                    'rejectedNameOnlyCandidates' => [],
                    'reason' => $ignored !== []
                        ? 'Internal classification is not Catalogue Navigation.'
                        : 'No legacy catalogue navigation placement found.',
                ]],
                'ignoredProductCategorySignals' => $ignored,
            ];
        }

        $resolutions = [];
        foreach ($usable as $rel) {
            $resolutions[] = $this->resolveRelationship(
                $product,
                $rel,
                $nodes,
                $nodeById,
                $nodeByCode,
                $pathIndex,
                $tabs,
                $protectedLinks,
                $explicitMaps,
            );
        }

        return [
            'resolutions' => $resolutions,
            'ignoredProductCategorySignals' => $ignored,
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $rel
     * @param list<array<string, mixed>> $nodes
     * @param array<string, array<string, mixed>> $nodeById
     * @param array<string, array<string, mixed>> $nodeByCode
     * @param array<string, list<array<string, mixed>>> $pathIndex
     * @param list<array<string, mixed>> $tabs
     * @param list<array<string, mixed>> $protectedLinks
     * @param list<array<string, mixed>> $explicitMaps
     * @return array<string, mixed>
     */
    private function resolveRelationship(
        array $product,
        array $rel,
        array $nodes,
        array $nodeById,
        array $nodeByCode,
        array $pathIndex,
        array $tabs,
        array $protectedLinks,
        array $explicitMaps,
    ): array {
        $style = strtoupper(trim(MigrationPayload::string($product['styleCode'] ?? '')));
        $legacyPath = $this->normalizePath(MigrationPayload::string($rel['legacyPath'] ?? ''));
        $legacyTaxonomy = MigrationPayload::string($rel['legacyTaxonomy'] ?? '');
        $legacyTermId = isset($rel['legacyTermId']) ? MigrationPayload::string($rel['legacyTermId']) : null;
        $legacySlug = $this->normalizeCode(MigrationPayload::string($rel['legacyTermSlug'] ?? ''));
        $nameOnly = $this->nameOnlyCandidates(MigrationPayload::string($rel['legacyTermName'] ?? ''), $nodes);

        $explicitHits = $this->matchExplicit(
            $explicitMaps,
            $legacyPath,
            $legacyTaxonomy,
            $legacyTermId,
            $legacySlug,
        );
        $codeHits = $legacySlug === '' ? [] : $this->uniqueOrAll($nodeByCode, $legacySlug);
        $pathHits = $legacyPath === '' ? [] : ($pathIndex[$legacyPath] ?? []);

        $proven = [];
        $evidence = [];
        if ($explicitHits !== []) {
            foreach ($explicitHits as $hit) {
                $id = MigrationPayload::string($hit['targetCatalogueNodeId'] ?? $hit['targetNodePublicId'] ?? '');
                $code = $this->normalizeCode(MigrationPayload::string($hit['targetNodeCode'] ?? ''));
                $node = $id !== '' && isset($nodeById[$id])
                    ? $nodeById[$id]
                    : ($code !== '' && isset($nodeByCode[$code]) ? $nodeByCode[$code] : null);
                if ($node !== null && ($hit['operatorApproved'] ?? false) === true) {
                    $proven[] = $node;
                    $evidence[] = CatalogueNavigationDecision::EVIDENCE_EXPLICIT_APPROVED_MAP;
                }
            }
        }
        if ($proven === [] && count($codeHits) === 1) {
            $proven = $codeHits;
            $evidence[] = CatalogueNavigationDecision::EVIDENCE_EXACT_NODE_CODE;
        }
        if ($proven === [] && count($pathHits) === 1) {
            $proven = $pathHits;
            $evidence[] = CatalogueNavigationDecision::EVIDENCE_EXACT_PATH;
        }

        $ambiguousPool = [];
        if (count($explicitHits) > 1) {
            $ambiguousPool = $explicitHits;
        }
        if (count($codeHits) > 1) {
            $ambiguousPool = array_merge($ambiguousPool, $codeHits);
        }
        if (count($pathHits) > 1) {
            $ambiguousPool = array_merge($ambiguousPool, $pathHits);
        }

        $base = [
            'styleCode' => $style,
            'legacyProductName' => $product['legacyProductName'] ?? null,
            'wordpressPostId' => $product['wordpressPostId'] ?? null,
            'productPublicId' => $product['productPublicId'] ?? null,
            'legacyPath' => $rel['legacyPath'] ?? null,
            'legacyTaxonomy' => $legacyTaxonomy !== '' ? $legacyTaxonomy : null,
            'legacyTermId' => $legacyTermId,
            'legacyTermSlug' => $rel['legacyTermSlug'] ?? null,
            'rejectedNameOnlyCandidates' => $nameOnly,
            'migrationBatchId' => $product['migrationBatchId'] ?? 'projected-catalogue-navigation-batch',
        ];

        if ($nameOnly !== [] && $proven === []) {
            $evidence[] = CatalogueNavigationDecision::EVIDENCE_NAME_SIMILARITY_REJECTED;
        }

        if ($ambiguousPool !== [] && count($proven) !== 1) {
            return $base + $this->reviewRow(
                CatalogueNavigationDecision::REVIEW_AMBIGUOUS,
                'Multiple target CatalogueNodes match the legacy placement.',
                $evidence,
                $tabs,
                null,
            );
        }

        if ($proven === []) {
            return $base + $this->reviewRow(
                CatalogueNavigationDecision::REVIEW_TARGET_NODE_MISSING,
                'No proven existing CatalogueNode for this legacy placement. Nodes will not be created.',
                $evidence,
                $tabs,
                null,
            );
        }

        $node = $proven[0];
        $nodeId = MigrationPayload::string($node['publicId'] ?? '');
        $tab = $this->tabForNode($node, $tabs);
        $existing = $this->findExistingLink($protectedLinks, $product, $nodeId);
        if ($existing !== null) {
            return $base + [
                'targetTabCode' => $tab['tabCode'] ?? null,
                'targetTabName' => $tab['tabName'] ?? null,
                'targetNodeCode' => $node['nodeCode'] ?? null,
                'targetNodeName' => $node['nodeName'] ?? null,
                'targetNodePublicId' => $nodeId,
                'existingLink' => true,
                'existingLinkOwnership' => CatalogueNavigationDecision::OWNERSHIP_PRE_EXISTING_PROTECTED,
                'decision' => CatalogueNavigationDecision::EXISTING_IDENTICAL,
                'confidence' => CatalogueNavigationDecision::CONFIDENCE_HIGH,
                'projectedAction' => CatalogueNavigationDecision::ACTION_NOOP,
                'migrationAction' => 'EXISTING_IDENTICAL_PROTECTED',
                'ownership' => CatalogueNavigationDecision::OWNERSHIP_PRE_EXISTING_PROTECTED,
                'projectedRelationshipKey' => $existing['relationshipKey'] ?? ($nodeId . ':' . MigrationPayload::string($product['productPublicId'] ?? $style)),
                'evidence' => $evidence,
                'reason' => 'Identical Product ↔ CatalogueNode link already exists and remains PRE_EXISTING_PROTECTED.',
            ];
        }

        $conflicting = $this->conflictingProtectedLink($protectedLinks, $product, $nodeId);
        if ($conflicting !== null && ($product['singlePlacementRequired'] ?? false) === true) {
            return $base + $this->reviewRow(
                CatalogueNavigationDecision::REVIEW_CONFLICT,
                'Protected existing link differs from the proven target; migration will not move the product.',
                $evidence,
                $tabs,
                $node,
            ) + [
                'existingLink' => true,
                'existingLinkOwnership' => CatalogueNavigationDecision::OWNERSHIP_PRE_EXISTING_PROTECTED,
            ];
        }

        $batchId = MigrationPayload::string($product['migrationBatchId'] ?? 'projected-catalogue-navigation-batch');
        $productKey = MigrationPayload::string($product['productPublicId'] ?? $style);

        return $base + [
            'targetTabCode' => $tab['tabCode'] ?? null,
            'targetTabName' => $tab['tabName'] ?? null,
            'targetNodeCode' => $node['nodeCode'] ?? null,
            'targetNodeName' => $node['nodeName'] ?? null,
            'targetNodePublicId' => $nodeId,
            'existingLink' => false,
            'existingLinkOwnership' => null,
            'decision' => CatalogueNavigationDecision::MAP_EXISTING_NODE,
            'confidence' => CatalogueNavigationDecision::CONFIDENCE_HIGH,
            'projectedAction' => CatalogueNavigationDecision::ACTION_INSERT,
            'migrationAction' => 'ADD_ONLY',
            'ownership' => CatalogueNavigationDecision::OWNERSHIP_MIGRATION_CREATED,
            'projectedRelationshipKey' => $batchId . ':' . $productKey . ':' . $nodeId,
            'evidence' => $evidence,
            'reason' => 'Missing proven Product ↔ existing CatalogueNode relationship may be inserted additively.',
            'audit' => [
                'sourceSystemCode' => 'wordpress',
                'sourceProductKey' => $style,
                'sourceTable' => MigrationPayload::string($rel['legacyTaxonomy'] ?? 'wp_term_relationships'),
                'targetEntityType' => 'CatalogueNodeProduct',
                'ownership' => CatalogueNavigationDecision::OWNERSHIP_MIGRATION_CREATED,
                'migrationBatchId' => $batchId,
            ],
        ];
    }

    /**
     * @param list<string> $evidence
     * @param list<array<string, mixed>> $tabs
     * @param array<string, mixed>|null $node
     * @return array<string, mixed>
     */
    private function reviewRow(string $decision, string $reason, array $evidence, array $tabs, ?array $node): array
    {
        $tab = $node !== null ? $this->tabForNode($node, $tabs) : [];

        return [
            'targetTabCode' => $tab['tabCode'] ?? null,
            'targetTabName' => $tab['tabName'] ?? null,
            'targetNodeCode' => $node['nodeCode'] ?? null,
            'targetNodeName' => $node['nodeName'] ?? null,
            'targetNodePublicId' => $node['publicId'] ?? null,
            'existingLink' => false,
            'existingLinkOwnership' => null,
            'decision' => $decision,
            'confidence' => CatalogueNavigationDecision::CONFIDENCE_NONE,
            'projectedAction' => CatalogueNavigationDecision::ACTION_REVIEW,
            'migrationAction' => 'REVIEW',
            'ownership' => null,
            'projectedRelationshipKey' => null,
            'evidence' => $evidence,
            'reason' => $reason,
        ];
    }

    /**
     * @param list<array<string, mixed>> $maps
     * @return list<array<string, mixed>>
     */
    private function matchExplicit(
        array $maps,
        string $legacyPath,
        string $legacyTaxonomy,
        ?string $legacyTermId,
        string $legacySlug,
    ): array {
        $hits = [];
        foreach ($maps as $map) {
            if (($map['operatorApproved'] ?? false) !== true) {
                continue;
            }
            $mapPath = $this->normalizePath(MigrationPayload::string($map['legacyPath'] ?? ''));
            $mapTax = MigrationPayload::string($map['legacyTaxonomy'] ?? '');
            $mapTerm = isset($map['legacyTermId']) ? MigrationPayload::string($map['legacyTermId']) : '';
            $mapSlug = $this->normalizeCode(MigrationPayload::string($map['legacyTermSlug'] ?? $map['legacyKey'] ?? ''));
            $matched = false;
            if ($legacyTermId !== null && $mapTerm !== '' && $legacyTermId === $mapTerm) {
                $matched = true;
            }
            if ($mapPath !== '' && $legacyPath !== '' && $mapPath === $legacyPath) {
                $matched = true;
            }
            if ($mapSlug !== '' && $legacySlug !== '' && $mapSlug === $legacySlug && ($mapTax === '' || $mapTax === $legacyTaxonomy)) {
                $matched = true;
            }
            if ($matched) {
                $hits[] = $map;
            }
        }

        return $hits;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array{nodeCode: string, nodeName: string, reason: string}>
     */
    private function nameOnlyCandidates(string $name, array $nodes): array
    {
        $want = $this->normalizeName($name);
        if ($want === '') {
            return [];
        }
        $hits = [];
        foreach ($nodes as $node) {
            if ($this->normalizeName(MigrationPayload::string($node['nodeName'] ?? '')) === $want) {
                $hits[] = [
                    'nodeCode' => MigrationPayload::string($node['nodeCode'] ?? ''),
                    'nodeName' => MigrationPayload::string($node['nodeName'] ?? ''),
                    'reason' => CatalogueNavigationDecision::EVIDENCE_NAME_SIMILARITY_REJECTED,
                ];
            }
        }

        return $hits;
    }

    /**
     * @param list<array<string, mixed>> $links
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private function findExistingLink(array $links, array $product, string $nodeId): ?array
    {
        $productId = MigrationPayload::string($product['productPublicId'] ?? '');
        $style = strtoupper(trim(MigrationPayload::string($product['styleCode'] ?? '')));
        foreach ($links as $link) {
            $linkNode = MigrationPayload::string($link['catalogueNodePublicId'] ?? '');
            if ($linkNode !== $nodeId) {
                continue;
            }
            $linkProduct = MigrationPayload::string($link['productPublicId'] ?? '');
            $linkStyle = strtoupper(trim(MigrationPayload::string($link['styleCode'] ?? '')));
            if ($productId !== '' && $linkProduct === $productId) {
                return $link;
            }
            if ($style !== '' && $linkStyle === $style) {
                return $link;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $links
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private function conflictingProtectedLink(array $links, array $product, string $nodeId): ?array
    {
        $productId = MigrationPayload::string($product['productPublicId'] ?? '');
        $style = strtoupper(trim(MigrationPayload::string($product['styleCode'] ?? '')));
        foreach ($links as $link) {
            $linkNode = MigrationPayload::string($link['catalogueNodePublicId'] ?? '');
            if ($linkNode === $nodeId) {
                continue;
            }
            $linkProduct = MigrationPayload::string($link['productPublicId'] ?? '');
            $linkStyle = strtoupper(trim(MigrationPayload::string($link['styleCode'] ?? '')));
            if (($productId !== '' && $linkProduct === $productId) || ($style !== '' && $linkStyle === $style)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return list<array<string, mixed>>
     */
    private function markProtected(array $links): array
    {
        $out = [];
        foreach ($links as $i => $link) {
            $nodeId = MigrationPayload::string($link['catalogueNodePublicId'] ?? '');
            $productId = MigrationPayload::string($link['productPublicId'] ?? $link['styleCode'] ?? 'unknown');
            $out[] = $link + [
                'protection' => CatalogueNavigationDecision::OWNERSHIP_PRE_EXISTING_PROTECTED,
                'ownership' => CatalogueNavigationDecision::OWNERSHIP_PRE_EXISTING_PROTECTED,
                'relationshipKey' => MigrationPayload::string($link['relationshipKey'] ?? ('pre-existing:' . $productId . ':' . $nodeId . ':' . (string) $i)),
                'rollbackEligible' => false,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $tabs
     * @return array<string, list<array<string, mixed>>>
     */
    private function pathIndex(array $nodes, array $tabs): array
    {
        $tabById = $this->index($tabs, 'publicId', false);
        $nodeById = $this->index($nodes, 'publicId', false);
        $index = [];
        foreach ($nodes as $node) {
            $parts = [];
            $tabId = MigrationPayload::string($node['tabPublicId'] ?? '');
            if ($tabId !== '' && isset($tabById[$tabId])) {
                $parts[] = MigrationPayload::string($tabById[$tabId]['slug'] ?? $tabById[$tabId]['tabCode'] ?? '');
            }
            $chain = [];
            $current = $node;
            $guard = 0;
            while ($current !== null && $guard < 20) {
                array_unshift($chain, MigrationPayload::string($current['slug'] ?? $current['nodeCode'] ?? ''));
                $parentId = MigrationPayload::string($current['parentPublicId'] ?? '');
                $current = $parentId !== '' && isset($nodeById[$parentId]) ? $nodeById[$parentId] : null;
                $guard++;
            }
            $path = $this->normalizePath(implode('/', array_merge($parts, $chain)));
            if ($path === '') {
                continue;
            }
            $index[$path] ??= [];
            $index[$path][] = $node;
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $tabs
     * @return array<string, mixed>
     */
    private function tabForNode(array $node, array $tabs): array
    {
        $tabId = MigrationPayload::string($node['tabPublicId'] ?? '');
        foreach ($tabs as $tab) {
            if (MigrationPayload::string($tab['publicId'] ?? '') === $tabId) {
                return $tab;
            }
        }

        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $byCode
     * @return list<array<string, mixed>>
     */
    private function uniqueOrAll(array $byCode, string $code): array
    {
        if ($code === '' || !isset($byCode[$code])) {
            return [];
        }

        return [$byCode[$code]];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function index(array $rows, string $key, bool $uppercase): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = trim(MigrationPayload::string($row[$key] ?? ''));
            if ($uppercase) {
                $id = strtoupper($id);
            }
            if ($id === '') {
                continue;
            }
            $out[$id] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $key): array
    {
        $counts = [
            CatalogueNavigationDecision::MAP_EXISTING_NODE => 0,
            CatalogueNavigationDecision::EXISTING_IDENTICAL => 0,
            CatalogueNavigationDecision::REVIEW_AMBIGUOUS => 0,
            CatalogueNavigationDecision::REVIEW_TARGET_NODE_MISSING => 0,
            CatalogueNavigationDecision::REVIEW_CONFLICT => 0,
            CatalogueNavigationDecision::NOT_APPLICABLE => 0,
            CatalogueNavigationDecision::ACTION_INSERT => 0,
            CatalogueNavigationDecision::ACTION_NOOP => 0,
            CatalogueNavigationDecision::ACTION_REVIEW => 0,
        ];
        foreach ($rows as $row) {
            $value = MigrationPayload::string($row[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function records(mixed $value): array
    {
        return MigrationPayload::assocList($value);
    }

    /**
     * @param list<array<string, mixed>> $resolutions
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function f12Section(array $resolutions, array $input): array
    {
        $f12 = null;
        foreach ($resolutions as $row) {
            if (strtoupper(MigrationPayload::string($row['styleCode'] ?? '')) === 'F12') {
                $f12 = $row;
                break;
            }
        }
        $forced = is_array($input['f12'] ?? null) ? $input['f12'] : [];

        return [
            'styleCode' => 'F12',
            'legacyCataloguePath' => $f12['legacyPath'] ?? ($forced['legacyCataloguePath'] ?? null),
            'legacyTaxonomy' => $f12['legacyTaxonomy'] ?? ($forced['legacyTaxonomy'] ?? null),
            'targetCatalogueTab' => $f12['targetTabName'] ?? ($f12['targetTabCode'] ?? null),
            'targetCatalogueNode' => $f12['targetNodeName'] ?? ($f12['targetNodeCode'] ?? null),
            'existingLink' => $f12['existingLink'] ?? false,
            'projectedAction' => $f12['projectedAction'] ?? CatalogueNavigationDecision::ACTION_REVIEW,
            'decision' => $f12['decision'] ?? CatalogueNavigationDecision::NOT_APPLICABLE,
            'confidence' => $f12['confidence'] ?? CatalogueNavigationDecision::CONFIDENCE_NONE,
            'relationshipProjection' => ($f12['projectedAction'] ?? null) === CatalogueNavigationDecision::ACTION_INSERT ? 1 : 0,
            'applyBlockedBySourceDrift' => true,
            'note' => 'F12 product/variant apply remains blocked on PILOT_SOURCE_DRIFT. This task does not apply F12.',
        ];
    }

    private function normalizePath(string $path): string
    {
        $trimmed = strtolower(trim(str_replace('\\', '/', $path)));
        $trimmed = trim($trimmed, '/');
        $collapsed = preg_replace('#/+#', '/', $trimmed);

        return is_string($collapsed) ? $collapsed : $trimmed;
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function normalizeName(string $name): string
    {
        $lower = strtolower(trim($name));
        $collapsed = preg_replace('/\s+/', ' ', $lower);

        return is_string($collapsed) ? $collapsed : $lower;
    }
}
