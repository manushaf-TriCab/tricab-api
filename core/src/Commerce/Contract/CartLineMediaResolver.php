<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Contract;

use TriCab\Core\Commerce\Application\DTO\CartLineMedia;

/**
 * Batch-resolves cart-line product/variant media. Implementations must not query per line.
 */
interface CartLineMediaResolver
{
    /**
     * @param list<string> $variantPublicIds
     * @return array<string, CartLineMedia> keyed by variant public ID
     */
    public function resolveForVariantPublicIds(array $variantPublicIds): array;
}
