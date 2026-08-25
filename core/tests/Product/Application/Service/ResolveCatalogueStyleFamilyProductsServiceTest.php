<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\Service\ResolveCatalogueStyleFamilyProductsService;

final class ResolveCatalogueStyleFamilyProductsServiceTest extends TestCase
{
    private ResolveCatalogueStyleFamilyProductsService $service;

    protected function setUp(): void
    {
        $this->service = new ResolveCatalogueStyleFamilyProductsService();
    }

    public function test_bv_linked_style_includes_descendant_products(): void
    {
        $indexes = $this->familyIndexes(
            parent: ['code' => 'BV', 'publicId' => 'bv'],
            children: [
                ['code' => 'BVA', 'publicId' => 'bva', 'parent' => 'bv', 'product' => 'prod-bva'],
                ['code' => 'BVC', 'publicId' => 'bvc', 'parent' => 'bv', 'product' => 'prod-bvc'],
                ['code' => 'BVM', 'publicId' => 'bvm', 'parent' => 'bv', 'product' => 'prod-bvm'],
                ['code' => 'BVP', 'publicId' => 'bvp', 'parent' => 'bv', 'product' => 'prod-bvp'],
            ],
            published: ['prod-bva'],
            regions: ['prod-bva' => ['AU']],
        );

        $resolved = $this->service->resolveForLinkedStyle(
            'BV',
            $indexes['stylesByCode'],
            $indexes['childrenByParentPublicId'],
            $indexes['productsByStylePublicId'],
            $indexes['productsByPublicId'],
            $indexes['regionCodesByProductPublicId'],
            'AU',
            true,
        );

        self::assertSame(['BV', 'BVA', 'BVC', 'BVM', 'BVP'], $resolved['descendantStyleCodes']);
        self::assertCount(4, $resolved['candidateProducts']);
        self::assertCount(1, $resolved['visibleProducts']);
        self::assertSame('prod-bva', $resolved['visibleProducts'][0]['productPublicId']);
    }

    public function test_pjt_is_excluded_from_pj_resolution(): void
    {
        $indexes = $this->familyIndexes(
            parent: ['code' => 'PJ', 'publicId' => 'pj'],
            children: [
                ['code' => 'PJA', 'publicId' => 'pja', 'parent' => 'pj', 'product' => 'prod-pja'],
            ],
            extraStyles: [
                ['code' => 'PJT', 'publicId' => 'pjt', 'parent' => null, 'product' => 'prod-pjt'],
            ],
            published: ['prod-pja', 'prod-pjt'],
            regions: ['prod-pja' => ['AU'], 'prod-pjt' => ['AU']],
        );

        $resolved = $this->service->resolveForLinkedStyle(
            'PJ',
            $indexes['stylesByCode'],
            $indexes['childrenByParentPublicId'],
            $indexes['productsByStylePublicId'],
            $indexes['productsByPublicId'],
            $indexes['regionCodesByProductPublicId'],
            'AU',
            true,
        );

        self::assertSame(['PJ', 'PJA'], $resolved['descendantStyleCodes']);
        self::assertCount(1, $resolved['candidateProducts']);
        self::assertSame('prod-pja', $resolved['candidateProducts'][0]['productPublicId']);
    }

    public function test_bva_x_without_assignment_contributes_no_product(): void
    {
        $indexes = $this->familyIndexes(
            parent: ['code' => 'BV', 'publicId' => 'bv'],
            children: [
                ['code' => 'BVA', 'publicId' => 'bva', 'parent' => 'bv', 'product' => 'prod-bva'],
                ['code' => 'BVA-X', 'publicId' => 'bva-x', 'parent' => 'bva'],
            ],
            published: ['prod-bva'],
            regions: ['prod-bva' => ['AU']],
        );

        $resolved = $this->service->resolveForLinkedStyle(
            'BV',
            $indexes['stylesByCode'],
            $indexes['childrenByParentPublicId'],
            $indexes['productsByStylePublicId'],
            $indexes['productsByPublicId'],
            $indexes['regionCodesByProductPublicId'],
            'AU',
            false,
        );

        self::assertContains('BVA-X', $resolved['descendantStyleCodes']);
        self::assertCount(1, $resolved['candidateProducts']);
    }

    public function test_up_linked_style_includes_parent_and_descendants(): void
    {
        $indexes = $this->familyIndexes(
            parent: ['code' => 'UP', 'publicId' => 'up', 'product' => 'prod-up'],
            children: [
                ['code' => 'UPA', 'publicId' => 'upa', 'parent' => 'up', 'product' => 'prod-upa'],
                ['code' => 'UPC', 'publicId' => 'upc', 'parent' => 'up', 'product' => 'prod-upc'],
            ],
            published: ['prod-up', 'prod-upa', 'prod-upc'],
            regions: ['prod-up' => ['AU'], 'prod-upa' => ['AU'], 'prod-upc' => ['AU']],
        );

        $resolved = $this->service->resolveForLinkedStyle(
            'UP',
            $indexes['stylesByCode'],
            $indexes['childrenByParentPublicId'],
            $indexes['productsByStylePublicId'],
            $indexes['productsByPublicId'],
            $indexes['regionCodesByProductPublicId'],
            'AU',
            false,
        );

        self::assertCount(3, $resolved['candidateProducts']);
    }

    public function test_merge_deduplicates_direct_and_style(): void
    {
        $merged = $this->service->mergeDirectAndStyleProducts(
            [
                ['productPublicId' => 'prod-bva', 'productCode' => 'BVA', 'productName' => 'BVA', 'displayOrder' => 0],
            ],
            [
                ['productPublicId' => 'prod-bva', 'productCode' => 'BVA', 'productName' => 'BVA', 'resolvedViaStyleCode' => 'BVA'],
                ['productPublicId' => 'prod-bvc', 'productCode' => 'BVC', 'productName' => 'BVC', 'resolvedViaStyleCode' => 'BVC'],
            ],
        );

        self::assertSame(1, $merged['bothCount']);
        self::assertSame(1, $merged['styleOnlyCount']);
        self::assertCount(2, $merged['products']);
        self::assertSame('DIRECT+STYLE', $merged['products'][0]['provenance']);
        self::assertSame('STYLE', $merged['products'][1]['provenance']);
    }

    /**
     * @param array{code: string, publicId: string, product?: string} $parent
     * @param list<array{code: string, publicId: string, parent?: ?string, product?: string}> $children
     * @param list<array{code: string, publicId: string, parent?: ?string, product?: string}> $extraStyles
     * @param list<string> $published
     * @param array<string, list<string>> $regions
     * @return array<string, mixed>
     */
    private function familyIndexes(
        array $parent,
        array $children = [],
        array $extraStyles = [],
        array $published = [],
        array $regions = [],
    ): array {
        $stylesByCode = [];
        $childrenByParentPublicId = [];
        $productsByStylePublicId = [];
        $productsByPublicId = [];
        $regionCodesByProductPublicId = $regions;

        $all = array_merge([$parent], $children, $extraStyles);
        foreach ($all as $style) {
            $code = strtoupper($style['code']);
            $publicId = $style['publicId'];
            $parentPublicId = $style['parent'] ?? null;
            $stylesByCode[$code] = [
                'publicId' => $publicId,
                'styleCode' => $code,
                'displayName' => $code,
                'isActive' => true,
                'isDeleted' => false,
                'sortOrder' => 0,
                'parentPublicId' => $parentPublicId,
            ];
            if ($parentPublicId !== null) {
                $childrenByParentPublicId[$parentPublicId][] = $publicId;
            }
            if (isset($style['product'])) {
                $productPublicId = $style['product'];
                $productsByStylePublicId[$publicId][] = [
                    'productPublicId' => $productPublicId,
                    'productCode' => $code,
                    'productName' => $code,
                ];
                $productsByPublicId[$productPublicId] = [
                    'internalCode' => $code,
                    'productName' => $code,
                    'isActive' => true,
                    'deletedAtUtc' => null,
                    'publicationStatus' => in_array($productPublicId, $published, true) ? 'published' : 'draft',
                    'publishAtUtc' => null,
                    'unpublishAtUtc' => null,
                ];
            } else {
                $productsByStylePublicId[$publicId] = [];
            }
        }

        return compact(
            'stylesByCode',
            'childrenByParentPublicId',
            'productsByStylePublicId',
            'productsByPublicId',
            'regionCodesByProductPublicId',
        );
    }
}
