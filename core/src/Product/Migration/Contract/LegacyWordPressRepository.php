<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Contract;

/**
 * Strictly read-only WordPress product inspection API. No save/update/delete.
 */
interface LegacyWordPressRepository
{
    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findProducts(array $filter = []): array;

    /**
     * @param list<int|string> $postIds
     * @return list<array<string, mixed>>
     */
    public function findAttachments(array $postIds): array;

    /**
     * @param list<int|string> $postIds
     * @return list<array<string, mixed>>
     */
    public function findCategories(array $postIds): array;

    /**
     * @param list<int|string> $postIds
     * @return list<array<string, mixed>>
     */
    public function findSpecifications(array $postIds): array;

    /**
     * @param list<int|string> $postIds
     * @return list<array<string, mixed>>
     */
    public function findRelationships(array $postIds): array;

    /**
     * @return array{tables: list<string>, columns: array<string, list<string>>, productCounts: array<string, int>}
     */
    public function inspectSchema(): array;

    /**
     * @return list<array{post_type: string, post_status: string, total: int}>
     */
    public function discoverPostTypeCounts(): array;

    /**
     * @param list<string> $postTypes
     * @return list<array<string, mixed>>
     */
    public function discoverProductTaxonomies(array $postTypes): array;

    /**
     * @param list<string> $postTypes
     * @return list<array<string, mixed>>
     */
    public function discoverProductMetaKeys(array $postTypes): array;

    /**
     * @param list<string> $postTypes
     * @return list<array{style_code: string, product_count: int, statuses: string}>
     */
    public function discoverStyleCodes(array $postTypes): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function discoverNavMenus(): array;

    /**
     * Read-only catalogue/navigation placements for style codes.
     *
     * @param list<string> $styleCodes
     * @param list<string> $postTypes
     * @return list<array<string, mixed>>
     */
    public function discoverCataloguePlacements(array $styleCodes, array $postTypes = ['cable', 'accessory']): array;

    /**
     * @param list<string> $postTypes
     * @return list<array<string, mixed>>
     */
    public function discoverProductAttachments(array $postTypes): array;

    /**
     * Product identity inventory (batched). Includes style_code raw meta.
     *
     * @param list<string> $postTypes
     * @return list<array<string, mixed>>
     */
    public function discoverProductIdentityRows(array $postTypes): array;

    /**
     * Distinct meta values for a known ACF/meta key on product posts.
     *
     * @param list<string> $postTypes
     * @return list<array{value: string, usage_count: int}>
     */
    public function discoverMetaValueCounts(array $postTypes, string $metaKey, int $limit = 500): array;

    /**
     * Certificate CPT inventory for document classification (not Products).
     *
     * @return list<array<string, mixed>>
     */
    public function discoverCertificates(int $limit = 500): array;
}
