<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CatalogueNavigation;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\CatalogueNavigationDryRunCliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveCatalogueNavigationAdditiveMappingService;
use TriCab\Core\Product\Migration\CatalogueNavigation\CatalogueNavigationDecision;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class ResolveCatalogueNavigationAdditiveMappingServiceTest extends TestCase
{
    public function testApplyFlagsAreRefused(): void
    {
        self::assertSame(
            CatalogueNavigationDryRunCliGuard::APPLY_NOT_APPROVED,
            CatalogueNavigationDryRunCliGuard::refuseApply(['bin.php', '--apply']),
        );
        self::assertNull(CatalogueNavigationDryRunCliGuard::refuseApply(['bin.php', '--dry-run']));
    }

    public function testCatalogueStructureIsNeverProjectedForWrite(): void
    {
        $data = $this->service()->dryRun($this->input())->toArray();
        self::assertSame(0, MigrationPayload::int($data['catalogueTabWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['catalogueNodeWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['productNodeWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['wordpressWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['tcWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['forbiddenWriteAttempts'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['targetWrites'] ?? 1));
        self::assertFalse(MigrationPayload::bool($data['applied'] ?? true, true));
        $mutations = $this->mutations($this->payload($data));
        self::assertSame(0, MigrationPayload::int($mutations['catalogueTabWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($mutations['catalogueNodeWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($mutations['productNodeDeletes'] ?? 1));
        self::assertSame(0, MigrationPayload::int($mutations['productNodeMoves'] ?? 1));
        self::assertSame(0, MigrationPayload::int($this->safety($this->payload($data))['productNodeWrites'] ?? 1));
    }

    public function testExistingManualLinkIsProtectedIdenticalNoop(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('A10', [
                $this->rel('products/accessories', 'product_cat', '11', 'PROD-ACCESSORIES'),
            ], 'prod-a10')],
            'target' => $this->target([[
                'productPublicId' => 'prod-a10',
                'styleCode' => 'A10',
                'catalogueNodePublicId' => 'node-acc',
            ]]),
        ]))->toArray());
        $row = $this->firstResolution($payload);
        self::assertSame(CatalogueNavigationDecision::EXISTING_IDENTICAL, $row['decision']);
        self::assertSame(CatalogueNavigationDecision::ACTION_NOOP, $row['projectedAction']);
        self::assertSame(CatalogueNavigationDecision::OWNERSHIP_PRE_EXISTING_PROTECTED, $row['ownership']);
        self::assertSame('EXISTING_IDENTICAL_PROTECTED', $row['migrationAction']);
        $protected = MigrationPayload::assocList($payload['protectedExistingLinks'] ?? []);
        self::assertFalse(MigrationPayload::bool($protected[0]['rollbackEligible'] ?? true, true));
    }

    public function testMissingProvenLinkProjectsInsertWithMigrationOwnership(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('F12', [
                $this->rel('products/accessories', 'nav_menu', '22', 'PROD-ACCESSORIES'),
            ])],
        ]))->toArray());
        $row = $this->firstResolution($payload);
        self::assertSame(CatalogueNavigationDecision::MAP_EXISTING_NODE, $row['decision']);
        self::assertSame(CatalogueNavigationDecision::ACTION_INSERT, $row['projectedAction']);
        self::assertSame('ADD_ONLY', $row['migrationAction']);
        self::assertSame(CatalogueNavigationDecision::OWNERSHIP_MIGRATION_CREATED, $row['ownership']);
        self::assertSame(CatalogueNavigationDecision::CONFIDENCE_HIGH, $row['confidence']);
        self::assertSame(1, MigrationPayload::int($this->mutations($payload)['productNodeInserts'] ?? 0));
        $eligible = MigrationPayload::assocList(MigrationPayload::assoc($payload['rollbackProjection'] ?? [])['eligible'] ?? []);
        self::assertTrue(MigrationPayload::bool($eligible[0]['rollbackEligible'] ?? false));
        self::assertSame(CatalogueNavigationDecision::OWNERSHIP_MIGRATION_CREATED, $eligible[0]['ownership']);
    }

    public function testAmbiguousPathGoesToReview(): void
    {
        $target = $this->target();
        $nodes = MigrationPayload::assocList($target['nodes'] ?? []);
        $nodes[] = [
            'publicId' => 'node-acc-2',
            'tabPublicId' => 'tab-products',
            'parentPublicId' => null,
            'nodeCode' => 'PROD-ACCESSORIES-ALT',
            'nodeName' => 'Accessories Alt',
            'slug' => 'accessories',
            'displayOrder' => 2,
            'isActive' => true,
        ];
        $target['nodes'] = $nodes;
        $row = $this->firstResolution($this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('X1', [
                $this->rel('products/accessories', 'product_cat', '9', 'not-a-code'),
            ])],
            'target' => $target,
        ]))->toArray()));
        self::assertSame(CatalogueNavigationDecision::REVIEW_AMBIGUOUS, $row['decision']);
        self::assertSame('REVIEW', $row['migrationAction']);
    }

    public function testMissingTargetNodeGoesToReviewWithoutCreatingNodes(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('Y1', [
                $this->rel('products/unknown-leaf', 'product_cat', '99', 'NO-SUCH-NODE'),
            ])],
        ]))->toArray());
        self::assertSame(
            CatalogueNavigationDecision::REVIEW_TARGET_NODE_MISSING,
            $this->firstResolution($payload)['decision'],
        );
        self::assertSame(0, MigrationPayload::int($this->mutations($payload)['catalogueNodeWrites'] ?? 1));
    }

    public function testProductCategoryIsNotACatalogueNode(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [[
                'styleCode' => 'F12',
                'legacyProductName' => 'Inspection Tee',
                'productCategoryCode' => 'CONDUITS-FITTINGS',
                'catalogueRelationships' => [[
                    'sourceKind' => 'product_category_classification',
                    'legacyPath' => 'ACCESSORIES/CONDUITS-FITTINGS',
                    'legacyTermSlug' => 'CONDUITS-FITTINGS',
                ]],
            ]],
        ]))->toArray());
        self::assertSame(CatalogueNavigationDecision::NOT_APPLICABLE, $this->firstResolution($payload)['decision']);
        $ignored = MigrationPayload::assocList($payload['ignoredProductCategorySignals'] ?? []);
        self::assertSame(
            CatalogueNavigationDecision::EVIDENCE_PRODUCT_CATEGORY_REJECTED,
            $ignored[0]['reason'],
        );
    }

    public function testNameSimilarityAloneIsInsufficient(): void
    {
        $row = $this->firstResolution($this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('Z1', [[
                'sourceKind' => 'taxonomy',
                'legacyPath' => 'something-else',
                'legacyTaxonomy' => 'product_cat',
                'legacyTermId' => '5',
                'legacyTermSlug' => 'not-the-code',
                'legacyTermName' => 'Accessories',
            ]])],
        ]))->toArray()));
        self::assertSame(CatalogueNavigationDecision::REVIEW_TARGET_NODE_MISSING, $row['decision']);
        self::assertNotEmpty($row['rejectedNameOnlyCandidates']);
        self::assertContains(CatalogueNavigationDecision::EVIDENCE_NAME_SIMILARITY_REJECTED, MigrationPayload::stringList($row['evidence'] ?? []));
    }

    public function testRegionMustNotDuplicateCatalogueTree(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('F12', [[
                'sourceKind' => 'taxonomy',
                'legacyPath' => 'products/accessories',
                'legacyTermSlug' => 'PROD-ACCESSORIES',
                'regionDuplicatesTree' => true,
            ]])],
        ]))->toArray());
        self::assertSame(CatalogueNavigationDecision::NOT_APPLICABLE, $this->firstResolution($payload)['decision']);
        $ignored = MigrationPayload::assocList($payload['ignoredProductCategorySignals'] ?? []);
        self::assertSame(
            CatalogueNavigationDecision::EVIDENCE_REGION_TREE_REJECTED,
            $ignored[0]['reason'],
        );
    }

    public function testExplicitApprovedMapIsHighConfidence(): void
    {
        $row = $this->firstResolution($this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('F12', [[
                'sourceKind' => 'taxonomy',
                'legacyPath' => 'legacy/odd-path',
                'legacyTaxonomy' => 'accessory_category',
                'legacyTermId' => '77',
                'legacyTermSlug' => 'odd',
            ]])],
            'explicitMaps' => [[
                'legacyPath' => 'legacy/odd-path',
                'legacyTaxonomy' => 'accessory_category',
                'legacyTermId' => '77',
                'targetCatalogueNodeId' => 'node-acc',
                'targetNodeCode' => 'PROD-ACCESSORIES',
                'operatorApproved' => true,
            ]],
        ]))->toArray()));
        self::assertSame(CatalogueNavigationDecision::MAP_EXISTING_NODE, $row['decision']);
        self::assertContains(CatalogueNavigationDecision::EVIDENCE_EXPLICIT_APPROVED_MAP, MigrationPayload::stringList($row['evidence'] ?? []));
        self::assertSame(CatalogueNavigationDecision::CONFIDENCE_HIGH, $row['confidence']);
    }

    public function testUnapprovedExplicitMapIsIgnored(): void
    {
        $row = $this->firstResolution($this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('F12', [[
                'sourceKind' => 'taxonomy',
                'legacyPath' => 'legacy/odd-path',
                'legacyTermId' => '77',
                'legacyTermSlug' => 'odd',
            ]])],
            'explicitMaps' => [[
                'legacyPath' => 'legacy/odd-path',
                'legacyTermId' => '77',
                'targetCatalogueNodeId' => 'node-acc',
                'operatorApproved' => false,
            ]],
        ]))->toArray()));
        self::assertSame(CatalogueNavigationDecision::REVIEW_TARGET_NODE_MISSING, $row['decision']);
    }

    public function testConflictDoesNotMoveProtectedLink(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('A10', [
                $this->rel('products/glands', 'product_cat', '3', 'ACC-GLANDS'),
            ], 'prod-a10') + ['singlePlacementRequired' => true]],
            'target' => $this->target([[
                'productPublicId' => 'prod-a10',
                'styleCode' => 'A10',
                'catalogueNodePublicId' => 'node-acc',
            ]]),
        ]))->toArray());
        self::assertSame(CatalogueNavigationDecision::REVIEW_CONFLICT, $this->firstResolution($payload)['decision']);
        self::assertSame(0, MigrationPayload::int($this->mutations($payload)['productNodeMoves'] ?? 1));
    }

    public function testRollbackExcludesManualLinks(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [
                $this->product('A10', [
                    $this->rel('products/accessories', 'product_cat', '11', 'PROD-ACCESSORIES'),
                ], 'prod-a10'),
                $this->product('F12', [
                    $this->rel('products/accessories', 'product_cat', '11', 'PROD-ACCESSORIES'),
                ]),
            ],
            'target' => $this->target([[
                'productPublicId' => 'prod-a10',
                'styleCode' => 'A10',
                'catalogueNodePublicId' => 'node-acc',
            ]]),
        ]))->toArray());
        $rollback = MigrationPayload::assoc( $payload['rollbackProjection'] ?? []);
        foreach (MigrationPayload::assocList($rollback['eligible'] ?? []) as $row) {
            self::assertSame(CatalogueNavigationDecision::OWNERSHIP_MIGRATION_CREATED, $row['ownership']);
            self::assertNotSame('A10', $row['styleCode']);
        }
        self::assertSame(1, MigrationPayload::int($rollback['preservedProtectedCount'] ?? 0));
    }

    public function testF12ResolutionDoesNotApply(): void
    {
        $f12 = MigrationPayload::assoc($this->payload($this->service()->dryRun($this->input([
            'legacyProducts' => [$this->product('F12', [
                $this->rel('products/accessories', 'nav_menu', '22', 'PROD-ACCESSORIES'),
            ])],
        ]))->toArray())['f12'] ?? []);
        self::assertSame('F12', $f12['styleCode']);
        self::assertSame(CatalogueNavigationDecision::MAP_EXISTING_NODE, $f12['decision']);
        self::assertTrue(MigrationPayload::bool($f12['applyBlockedBySourceDrift'] ?? false));
        self::assertSame(1, MigrationPayload::int($f12['relationshipProjection'] ?? 0));
    }

    public function testDeterministic(): void
    {
        $service = $this->service();
        self::assertSame(
            $this->payload($service->dryRun($this->input())->toArray())['resolutions'],
            $this->payload($service->dryRun($this->input())->toArray())['resolutions'],
        );
    }

    public function testArtifactsExistAndAreValidJson(): void
    {
        $dir = dirname(__DIR__, 4) . '/docs/phase12.8';
        foreach ([
            'catalogue-navigation-existing-state.json',
            'catalogue-navigation-legacy-map.json',
            'catalogue-navigation-resolution.json',
            'catalogue-navigation-protected-links.json',
            'catalogue-navigation-f12-projection.json',
            'catalogue-navigation-final-dry-run.json',
            'catalogue-navigation-explicit-map.json',
        ] as $name) {
            $file = $dir . '/' . $name;
            self::assertFileExists($file);
            $decoded = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($decoded, $name);
        }
        $final = MigrationPayload::assoc(json_decode((string) file_get_contents($dir . '/catalogue-navigation-final-dry-run.json'), true));
        self::assertSame(0, MigrationPayload::int($final['wordpressWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($final['tcWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($final['catalogueTabWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($final['catalogueNodeWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($final['productNodeWrites'] ?? 1));
        self::assertFalse(MigrationPayload::bool($final['applied'] ?? true, true));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return MigrationPayload::assoc($data['payload'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function firstResolution(array $payload): array
    {
        $rows = MigrationPayload::assocList($payload['resolutions'] ?? []);
        self::assertNotEmpty($rows);

        return $rows[0];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mutations(array $payload): array
    {
        return MigrationPayload::assoc($payload['projectedMutations'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function safety(array $payload): array
    {
        return MigrationPayload::assoc($payload['safety'] ?? []);
    }

    private function service(): ResolveCatalogueNavigationAdditiveMappingService
    {
        return new ResolveCatalogueNavigationAdditiveMappingService();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return MigrationPayload::assoc(array_replace_recursive([
            'forbiddenWriteAttempts' => 0,
            'legacyProducts' => [],
            'explicitMaps' => [],
            'target' => $this->target(),
        ], $overrides));
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return array<string, mixed>
     */
    private function target(array $links = []): array
    {
        return [
            'tabs' => [[
                'publicId' => 'tab-products',
                'tabCode' => 'PRODUCTS',
                'tabName' => 'Products',
                'slug' => 'products',
                'displayOrder' => 1,
                'isActive' => true,
            ]],
            'nodes' => [
                [
                    'publicId' => 'node-acc',
                    'tabPublicId' => 'tab-products',
                    'parentPublicId' => null,
                    'nodeCode' => 'PROD-ACCESSORIES',
                    'nodeName' => 'Accessories',
                    'slug' => 'accessories',
                    'displayOrder' => 1,
                    'isActive' => true,
                ],
                [
                    'publicId' => 'node-glands',
                    'tabPublicId' => 'tab-products',
                    'parentPublicId' => 'node-acc',
                    'nodeCode' => 'ACC-GLANDS',
                    'nodeName' => 'Glands',
                    'slug' => 'glands',
                    'displayOrder' => 1,
                    'isActive' => true,
                ],
            ],
            'productNodeLinks' => $links,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rels
     * @return array<string, mixed>
     */
    private function product(string $style, array $rels, ?string $productPublicId = null): array
    {
        return [
            'styleCode' => $style,
            'legacyProductName' => $style,
            'productPublicId' => $productPublicId,
            'migrationBatchId' => 'batch-cat-nav-test',
            'catalogueRelationships' => $rels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rel(string $path, string $taxonomy, string $termId, string $slug): array
    {
        return [
            'sourceKind' => 'taxonomy',
            'legacyPath' => $path,
            'legacyTaxonomy' => $taxonomy,
            'legacyTermId' => $termId,
            'legacyTermSlug' => $slug,
            'legacyTermName' => $slug,
        ];
    }
}
