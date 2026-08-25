<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\Service\DiscoverCatalogueProductStyleMappingService;
use TriCab\Core\Product\Application\Service\ResolveCatalogueStyleFamilyProductsService;
use TriCab\Core\Tests\Product\Doubles\InMemoryCatalogueProductStyleEvidenceSource;

final class DiscoverCatalogueProductStyleMappingServiceTest extends TestCase
{
    private const NODE_BV = 'node-bv-0001';
    private const NODE_UP = 'node-up-0001';
    private const NODE_PJ = 'node-pj-0001';
    private const P_BVA = 'prod-bva-0001';
    private const P_BVC = 'prod-bvc-0001';
    private const P_BVM = 'prod-bvm-0001';
    private const P_BVP = 'prod-bvp-0001';
    private const P_UP = 'prod-up-00001';
    private const P_UPA = 'prod-upa-0001';
    private const P_PJA = 'prod-pja-0001';
    private const P_PJT = 'prod-pjt-0001';
    private const S_BV = 'style-bv-00001';
    private const S_BVA = 'style-bva-0001';
    private const S_BVC = 'style-bvc-0001';
    private const S_BVM = 'style-bvm-0001';
    private const S_BVP = 'style-bvp-0001';
    private const S_BVA_X = 'style-bvax-001';
    private const S_UP = 'style-up-00001';
    private const S_UPA = 'style-upa-0001';
    private const S_PJ = 'style-pj-00001';
    private const S_PJA = 'style-pja-0001';
    private const S_PJT = 'style-pjt-0001';

    private DiscoverCatalogueProductStyleMappingService $discover;
    private ResolveCatalogueStyleFamilyProductsService $resolver;

    protected function setUp(): void
    {
        $snapshot = $this->baseSnapshot();
        $this->discover = new DiscoverCatalogueProductStyleMappingService(
            new InMemoryCatalogueProductStyleEvidenceSource($snapshot),
        );
        $this->resolver = new ResolveCatalogueStyleFamilyProductsService();
    }

    public function test_bv_node_proposes_bv_with_exact_family_evidence(): void
    {
        $report = $this->discover->dryRun();
        $bv = $this->proposal($report, 'BV 110C Flame Retardant');
        self::assertSame('EXACT_FAMILY_EVIDENCE', $bv['decision']);
        self::assertSame('BV', $bv['proposedParentStyleCode']);
        self::assertSame(['BVA', 'BVC', 'BVM', 'BVP'], $bv['childStyleCodes']);
        self::assertSame(4, $bv['simulation']['byRegion']['ALL']['proposedVisibleCount']);
        self::assertSame(3, $bv['simulation']['byRegion']['ALL']['productsAdded']);
    }

    public function test_up_linked_style_includes_parent_and_descendant_products(): void
    {
        $indexes = $this->buildIndexesFromSnapshot($this->baseSnapshot());
        $resolved = $this->resolver->resolveForLinkedStyle(
            'UP',
            $indexes['stylesByCode'],
            $indexes['childrenByParentPublicId'],
            $indexes['productsByStylePublicId'],
            $indexes['productsByPublicId'],
            $indexes['regionCodesByProductPublicId'],
            'AU',
            true,
        );
        $codes = array_column($resolved['visibleProducts'], 'productCode');
        sort($codes);
        self::assertSame(['UP', 'UPA'], $codes);
    }

    public function test_pj_style_does_not_include_standalone_pjt(): void
    {
        $indexes = $this->buildIndexesFromSnapshot($this->baseSnapshot());
        $resolved = $this->resolver->resolveForLinkedStyle(
            'PJ',
            $indexes['stylesByCode'],
            $indexes['childrenByParentPublicId'],
            $indexes['productsByStylePublicId'],
            $indexes['productsByPublicId'],
            $indexes['regionCodesByProductPublicId'],
            null,
            true,
        );
        $codes = array_column($resolved['visibleProducts'], 'productCode');
        self::assertContains('PJA', $codes);
        self::assertNotContains('PJT', $codes);
    }

    public function test_deduplicates_direct_and_style_products(): void
    {
        $merged = $this->resolver->mergeDirectAndStyleProducts(
            [
                ['productPublicId' => self::P_BVA, 'productCode' => 'BVA', 'productName' => 'BVA', 'displayOrder' => 10],
            ],
            [
                ['productPublicId' => self::P_BVA, 'productCode' => 'BVA', 'productName' => 'BVA', 'resolvedViaStyleCode' => 'BVA'],
                ['productPublicId' => self::P_BVC, 'productCode' => 'BVC', 'productName' => 'BVC', 'resolvedViaStyleCode' => 'BVC'],
            ],
        );
        self::assertSame(2, count($merged['products']));
        self::assertSame(1, $merged['duplicatesRemoved']);
        self::assertSame('DIRECT+STYLE', $merged['products'][0]['provenance']);
    }

    public function test_dry_run_has_zero_writes(): void
    {
        $report = $this->discover->dryRun();
        self::assertSame(0, $report['writeGuard']['catalogueStyleLinkWrites']);
        self::assertSame(0, $report['writeGuard']['productStyleWrites']);
        self::assertFalse($report['assertions']['STYLE_RESOLUTION_USES_PREFIX_MATCHING']);
        self::assertTrue($report['assertions']['CATALOGUE_NAVIGATION_REMAINS_SEPARATE']);
    }

    public function test_region_filter_excludes_non_au_product(): void
    {
        $report = $this->discover->dryRun();
        $bv = $this->proposal($report, 'BV 110C Flame Retardant');
        self::assertSame(4, $bv['simulation']['byRegion']['ALL']['proposedVisibleCount']);
        self::assertSame(3, $bv['simulation']['byRegion']['AU']['proposedVisibleCount']);
        self::assertNotContains('BVC', $bv['simulation']['byRegion']['AU']['proposedProducts']);
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function proposal(array $report, string $nodeName): array
    {
        foreach ($report['proposals'] as $proposal) {
            if (($proposal['nodeName'] ?? '') === $nodeName) {
                return $proposal;
            }
        }
        self::fail('Proposal not found for ' . $nodeName);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseSnapshot(): array
    {
        return [
            'nodes' => [
                [
                    'publicId' => self::NODE_BV,
                    'nodeName' => 'BV 110C Flame Retardant',
                    'cataloguePath' => 'Marine / BV 110C Flame Retardant',
                    'directProductCount' => 0,
                ],
                [
                    'publicId' => self::NODE_UP,
                    'nodeName' => 'UP Node',
                    'cataloguePath' => 'Power / UP Node',
                    'directProductCount' => 0,
                ],
                [
                    'publicId' => self::NODE_PJ,
                    'nodeName' => 'PJ Node',
                    'cataloguePath' => 'Control / PJ Node',
                    'directProductCount' => 0,
                ],
            ],
            'directProducts' => [
                ['nodePublicId' => self::NODE_BV, 'productPublicId' => self::P_BVA, 'productCode' => 'BVA', 'productName' => 'BVA Product', 'displayOrder' => 10, 'isActive' => true],
            ],
            'styleAssignments' => [
                ['productPublicId' => self::P_BVA, 'productCode' => 'BVA', 'productName' => 'BVA Product', 'stylePublicId' => self::S_BVA, 'styleCode' => 'BVA', 'parentStylePublicId' => self::S_BV, 'parentStyleCode' => 'BV'],
                ['productPublicId' => self::P_BVC, 'productCode' => 'BVC', 'productName' => 'BVC Product', 'stylePublicId' => self::S_BVC, 'styleCode' => 'BVC', 'parentStylePublicId' => self::S_BV, 'parentStyleCode' => 'BV'],
                ['productPublicId' => self::P_BVM, 'productCode' => 'BVM', 'productName' => 'BVM Product', 'stylePublicId' => self::S_BVM, 'styleCode' => 'BVM', 'parentStylePublicId' => self::S_BV, 'parentStyleCode' => 'BV'],
                ['productPublicId' => self::P_BVP, 'productCode' => 'BVP', 'productName' => 'BVP Product', 'stylePublicId' => self::S_BVP, 'styleCode' => 'BVP', 'parentStylePublicId' => self::S_BV, 'parentStyleCode' => 'BV'],
                ['productPublicId' => self::P_UP, 'productCode' => 'UP', 'productName' => 'UP Product', 'stylePublicId' => self::S_UP, 'styleCode' => 'UP', 'parentStylePublicId' => null, 'parentStyleCode' => null],
                ['productPublicId' => self::P_UPA, 'productCode' => 'UPA', 'productName' => 'UPA Product', 'stylePublicId' => self::S_UPA, 'styleCode' => 'UPA', 'parentStylePublicId' => self::S_UP, 'parentStyleCode' => 'UP'],
                ['productPublicId' => self::P_PJA, 'productCode' => 'PJA', 'productName' => 'PJA Product', 'stylePublicId' => self::S_PJA, 'styleCode' => 'PJA', 'parentStylePublicId' => self::S_PJ, 'parentStyleCode' => 'PJ'],
                ['productPublicId' => self::P_PJT, 'productCode' => 'PJT', 'productName' => 'PJT Product', 'stylePublicId' => self::S_PJT, 'styleCode' => 'PJT', 'parentStylePublicId' => null, 'parentStyleCode' => null],
            ],
            'productStyles' => [
                ['publicId' => self::S_BV, 'styleCode' => 'BV', 'displayName' => 'BV', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => null],
                ['publicId' => self::S_BVA, 'styleCode' => 'BVA', 'displayName' => 'BVA', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => self::S_BV],
                ['publicId' => self::S_BVC, 'styleCode' => 'BVC', 'displayName' => 'BVC', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => self::S_BV],
                ['publicId' => self::S_BVM, 'styleCode' => 'BVM', 'displayName' => 'BVM', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => self::S_BV],
                ['publicId' => self::S_BVP, 'styleCode' => 'BVP', 'displayName' => 'BVP', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => self::S_BV],
                ['publicId' => self::S_BVA_X, 'styleCode' => 'BVA-X', 'displayName' => 'BVA-X', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => self::S_BVA],
                ['publicId' => self::S_UP, 'styleCode' => 'UP', 'displayName' => 'UP', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => null],
                ['publicId' => self::S_UPA, 'styleCode' => 'UPA', 'displayName' => 'UPA', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => self::S_UP],
                ['publicId' => self::S_PJ, 'styleCode' => 'PJ', 'displayName' => 'PJ', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => null],
                ['publicId' => self::S_PJA, 'styleCode' => 'PJA', 'displayName' => 'PJA', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => self::S_PJ],
                ['publicId' => self::S_PJT, 'styleCode' => 'PJT', 'displayName' => 'PJT', 'sortOrder' => 0, 'isActive' => true, 'isDeleted' => false, 'parentPublicId' => null],
            ],
            'products' => array_map(
                static fn (array $row): array => [
                    'publicId' => $row['productPublicId'],
                    'productName' => $row['productName'],
                    'internalCode' => $row['productCode'],
                    'isActive' => true,
                    'deletedAtUtc' => null,
                    'publishAtUtc' => null,
                    'unpublishAtUtc' => null,
                    'publicationStatus' => 'published',
                ],
                [
                    ['productPublicId' => self::P_BVA, 'productCode' => 'BVA', 'productName' => 'BVA Product'],
                    ['productPublicId' => self::P_BVC, 'productCode' => 'BVC', 'productName' => 'BVC Product'],
                    ['productPublicId' => self::P_BVM, 'productCode' => 'BVM', 'productName' => 'BVM Product'],
                    ['productPublicId' => self::P_BVP, 'productCode' => 'BVP', 'productName' => 'BVP Product'],
                    ['productPublicId' => self::P_UP, 'productCode' => 'UP', 'productName' => 'UP Product'],
                    ['productPublicId' => self::P_UPA, 'productCode' => 'UPA', 'productName' => 'UPA Product'],
                    ['productPublicId' => self::P_PJA, 'productCode' => 'PJA', 'productName' => 'PJA Product'],
                    ['productPublicId' => self::P_PJT, 'productCode' => 'PJT', 'productName' => 'PJT Product'],
                ],
            ),
            'productRegions' => [
                ['productPublicId' => self::P_BVA, 'regionCode' => 'AU'],
                ['productPublicId' => self::P_BVA, 'regionCode' => 'NZ'],
                ['productPublicId' => self::P_BVC, 'regionCode' => 'NZ'],
                ['productPublicId' => self::P_BVM, 'regionCode' => 'AU'],
                ['productPublicId' => self::P_BVP, 'regionCode' => 'AU'],
                ['productPublicId' => self::P_UP, 'regionCode' => 'AU'],
                ['productPublicId' => self::P_UPA, 'regionCode' => 'AU'],
                ['productPublicId' => self::P_PJA, 'regionCode' => 'AU'],
                ['productPublicId' => self::P_PJT, 'regionCode' => 'AU'],
            ],
            'regions' => ['AU', 'NZ', 'US'],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildIndexesFromSnapshot(array $snapshot): array
    {
        $discover = new DiscoverCatalogueProductStyleMappingService(
            new InMemoryCatalogueProductStyleEvidenceSource($snapshot),
        );
        $report = $discover->dryRun();
        unset($report);

        $stylesByCode = [];
        $childrenByParentPublicId = [];
        $productsByStylePublicId = [];
        $productsByPublicId = [];
        $regionCodesByProductPublicId = [];

        foreach ($snapshot['productStyles'] as $style) {
            $stylesByCode[strtoupper((string) $style['styleCode'])] = $style;
            $parentPublicId = (string) ($style['parentPublicId'] ?? '');
            if ($parentPublicId !== '') {
                $childrenByParentPublicId[$parentPublicId][] = (string) $style['publicId'];
            }
        }
        foreach ($snapshot['styleAssignments'] as $row) {
            $productsByStylePublicId[(string) $row['stylePublicId']][] = [
                'productPublicId' => (string) $row['productPublicId'],
                'productCode' => (string) $row['productCode'],
                'productName' => (string) $row['productName'],
            ];
        }
        foreach ($snapshot['products'] as $product) {
            $productsByPublicId[(string) $product['publicId']] = $product;
        }
        foreach ($snapshot['productRegions'] as $row) {
            $regionCodesByProductPublicId[(string) $row['productPublicId']][] = (string) $row['regionCode'];
        }

        return compact('stylesByCode', 'childrenByParentPublicId', 'productsByStylePublicId', 'productsByPublicId', 'regionCodesByProductPublicId');
    }
}
