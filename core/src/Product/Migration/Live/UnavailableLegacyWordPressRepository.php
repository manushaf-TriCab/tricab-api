<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;

final class UnavailableLegacyWordPressRepository implements LegacyWordPressRepository
{
    public function findProducts(array $filter = []): array
    {
        return [];
    }

    public function findAttachments(array $postIds): array
    {
        return [];
    }

    public function findCategories(array $postIds): array
    {
        return [];
    }

    public function findSpecifications(array $postIds): array
    {
        return [];
    }

    public function findRelationships(array $postIds): array
    {
        return [];
    }

    public function inspectSchema(): array
    {
        return ['tables' => [], 'columns' => [], 'productCounts' => []];
    }

    public function discoverPostTypeCounts(): array
    {
        return [];
    }

    public function discoverProductTaxonomies(array $postTypes): array
    {
        unset($postTypes);

        return [];
    }

    public function discoverProductMetaKeys(array $postTypes): array
    {
        unset($postTypes);

        return [];
    }

    public function discoverStyleCodes(array $postTypes): array
    {
        unset($postTypes);

        return [];
    }

    public function discoverNavMenus(): array
    {
        return [];
    }

    public function discoverCataloguePlacements(array $styleCodes, array $postTypes = ['cable', 'accessory']): array
    {
        unset($styleCodes, $postTypes);

        return [];
    }

    public function discoverProductAttachments(array $postTypes): array
    {
        unset($postTypes);

        return [];
    }

    public function discoverProductIdentityRows(array $postTypes): array
    {
        unset($postTypes);

        return [];
    }

    public function discoverMetaValueCounts(array $postTypes, string $metaKey, int $limit = 500): array
    {
        unset($postTypes, $metaKey, $limit);

        return [];
    }

    public function discoverCertificates(int $limit = 500): array
    {
        unset($limit);

        return [];
    }
}
