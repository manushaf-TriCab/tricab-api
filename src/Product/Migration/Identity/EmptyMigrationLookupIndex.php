<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Identity;

use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;
use TriCab\Core\Product\Migration\Contract\MigrationLookupIndex;

/** Default lookup index with no target hits (forces mapping / manual review paths in isolation). */
final class EmptyMigrationLookupIndex implements MigrationLookupIndex
{
    public function findProductPublicIdByExactSource(string $sourceKey): ?string
    {
        return null;
    }

    public function findProductPublicIdsByStyleCode(string $styleCode): array
    {
        return [];
    }

    public function findProductPublicIdsByProductCode(string $productCode): array
    {
        return [];
    }

    public function findProductPublicIdsBySku(string $sku): array
    {
        return [];
    }

    public function findProductPublicIdsByNormalizedSlug(string $slug): array
    {
        return [];
    }

    public function findApprovedCompositeMatch(CanonicalProduct $product): ?string
    {
        return null;
    }
}
