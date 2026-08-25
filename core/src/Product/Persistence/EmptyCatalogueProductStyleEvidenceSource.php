<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Persistence;

use TriCab\Core\Product\Contract\CatalogueProductStyleEvidenceSource;

/** Empty snapshot for non-Azure modes. */
final class EmptyCatalogueProductStyleEvidenceSource implements CatalogueProductStyleEvidenceSource
{
    public function loadSnapshot(): array
    {
        return [
            'nodes' => [],
            'directProducts' => [],
            'styleAssignments' => [],
            'productStyles' => [],
            'products' => [],
            'productRegions' => [],
            'regions' => [],
        ];
    }
}
