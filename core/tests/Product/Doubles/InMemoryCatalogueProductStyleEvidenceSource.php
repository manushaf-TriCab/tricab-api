<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use TriCab\Core\Product\Contract\CatalogueProductStyleEvidenceSource;

final class InMemoryCatalogueProductStyleEvidenceSource implements CatalogueProductStyleEvidenceSource
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        private readonly array $snapshot,
    ) {
    }

    public function loadSnapshot(): array
    {
        return $this->snapshot;
    }
}
