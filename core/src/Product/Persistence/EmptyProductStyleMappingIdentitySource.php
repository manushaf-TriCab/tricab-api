<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Persistence;

use TriCab\Core\Product\Contract\ProductStyleMappingIdentitySource;

/**
 * In-memory / test identity source. Never reads or writes live systems.
 */
final class EmptyProductStyleMappingIdentitySource implements ProductStyleMappingIdentitySource
{
    public function listProductSnapshots(): array
    {
        return [];
    }

    public function listTargetProducts(): array
    {
        return [];
    }

    public function unresolvedLegacyProducts(): array
    {
        return [];
    }

    public function wordpressReads(): int
    {
        return 0;
    }

    public function tcReads(): int
    {
        return 0;
    }

    public function filesystemReads(): int
    {
        return 0;
    }

    public function azureReads(): int
    {
        return 0;
    }
}
