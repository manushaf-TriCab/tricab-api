<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class RegionUsage
{
    public function __construct(
        public int $productRegionCount = 0,
        public int $marketCount = 0,
        public int $productDocumentCount = 0,
        public int $catalogueMappingCount = 0,
    ) {
    }

    public function total(): int
    {
        return $this->productRegionCount + $this->marketCount + $this->productDocumentCount + $this->catalogueMappingCount;
    }

    public function isUsed(): bool
    {
        return $this->total() > 0;
    }
}
