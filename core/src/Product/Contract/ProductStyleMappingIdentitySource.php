<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductStyleMappingSnapshot;
use TriCab\Core\Product\Application\DTO\UnresolvedLegacyStyleProduct;

/**
 * Read-only identity join of target Products to legacy style sources.
 * Implementations must not write Azure, WordPress, tc MySQL, or filesystem.
 */
interface ProductStyleMappingIdentitySource
{
    /**
     * @return list<ProductStyleMappingSnapshot>
     */
    public function listProductSnapshots(): array;

    /**
     * Azure target Products only. Does not read WordPress or tc.
     *
     * @return list<ProductStyleMappingSnapshot>
     */
    public function listTargetProducts(): array;

    /**
     * Legacy products that did not resolve to a target Product.
     *
     * @return list<UnresolvedLegacyStyleProduct>
     */
    public function unresolvedLegacyProducts(): array;

    public function wordpressReads(): int;

    public function tcReads(): int;

    public function filesystemReads(): int;

    public function azureReads(): int;
}
