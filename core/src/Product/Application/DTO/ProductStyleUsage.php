<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductStyleUsage
{
    public function __construct(
        public string $publicId,
        public int $productCount,
        public int $childCount,
        public int $activeChildCount,
        public int $deletedChildCount,
        public string $summary,
    ) {
    }

    public function summaryMessage(): string
    {
        return $this->summary;
    }

    public function hasBlockingChildren(): bool
    {
        return $this->childCount > 0;
    }

    public function hasActiveChildren(): bool
    {
        return $this->activeChildCount > 0;
    }

    public function hasProducts(): bool
    {
        return $this->productCount > 0;
    }
}
