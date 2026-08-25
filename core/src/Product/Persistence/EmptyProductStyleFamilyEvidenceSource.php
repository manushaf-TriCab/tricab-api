<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Persistence;

use TriCab\Core\Product\Contract\ProductStyleFamilyEvidenceSource;

/** In-memory / HTTP-test evidence. Never reads live systems. */
final class EmptyProductStyleFamilyEvidenceSource implements ProductStyleFamilyEvidenceSource
{
    public function load(array $productPublicIds): array
    {
        unset($productPublicIds);

        return [];
    }
}
