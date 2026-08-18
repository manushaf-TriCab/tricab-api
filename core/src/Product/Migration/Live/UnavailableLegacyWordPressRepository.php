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

}
