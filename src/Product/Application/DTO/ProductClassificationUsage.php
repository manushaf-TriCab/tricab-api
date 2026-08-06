<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductClassificationUsage
{
    public function __construct(
        public string $publicId,
        public string $level,
        public int $productCount,
        public int $activeCategoryCount = 0,
        public int $inactiveCategoryCount = 0,
        public int $activeSeriesCount = 0,
        public int $inactiveSeriesCount = 0,
        public int $deletedChildCount = 0,
    ) {
    }

    public function hasBlockingChildrenForDeactivate(): bool
    {
        return match ($this->level) {
            'family' => $this->activeCategoryCount > 0,
            'category' => $this->activeSeriesCount > 0,
            default => false,
        };
    }

    public function hasBlockingReferencesForArchive(): bool
    {
        return match ($this->level) {
            'family' => $this->productCount > 0
                || ($this->activeCategoryCount + $this->inactiveCategoryCount) > 0,
            'category' => $this->productCount > 0
                || ($this->activeSeriesCount + $this->inactiveSeriesCount) > 0,
            'series' => $this->productCount > 0,
            default => $this->productCount > 0,
        };
    }

    public function summaryMessage(): string
    {
        $parts = [];
        if ($this->activeCategoryCount + $this->inactiveCategoryCount > 0) {
            $parts[] = sprintf(
                '%d Categories',
                $this->activeCategoryCount + $this->inactiveCategoryCount,
            );
        }
        if ($this->activeSeriesCount + $this->inactiveSeriesCount > 0) {
            $parts[] = sprintf(
                '%d Series',
                $this->activeSeriesCount + $this->inactiveSeriesCount,
            );
        }
        if ($this->productCount > 0) {
            $parts[] = sprintf('%d Products', $this->productCount);
        }

        return $parts === [] ? 'No references.' : implode(' and ', $parts) . ' use it.';
    }
}
