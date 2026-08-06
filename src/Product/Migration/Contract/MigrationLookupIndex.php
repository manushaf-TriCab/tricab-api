<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Contract;

use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;

/**
 * Read model used by identity matching. Implementations must not invent matches.
 */
interface MigrationLookupIndex
{
    public function findProductPublicIdByExactSource(string $sourceKey): ?string;

    /** @return list<string> */
    public function findProductPublicIdsByStyleCode(string $styleCode): array;

    /** @return list<string> */
    public function findProductPublicIdsByProductCode(string $productCode): array;

    /** @return list<string> */
    public function findProductPublicIdsBySku(string $sku): array;

    /** @return list<string> */
    public function findProductPublicIdsByNormalizedSlug(string $slug): array;

    public function findApprovedCompositeMatch(CanonicalProduct $product): ?string;
}
