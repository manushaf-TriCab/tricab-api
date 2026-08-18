<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

use InvalidArgumentException;

final readonly class RegionListCriteria
{
    private const ALLOWED_SORTS = ['code', 'name', 'displayName', 'displayOrder', 'isDefault', 'isActive', 'updatedAt'];

    public function __construct(
        public int $page = 1,
        public int $pageSize = 25,
        public ?string $search = null,
        public ?bool $isActive = null,
        public ?bool $isDefault = null,
        public ?bool $websiteEnabled = null,
        public ?bool $showInRegionPicker = null,
        public bool $includeDeleted = false,
        public string $sortBy = 'displayOrder',
        public string $sortDirection = 'asc',
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be >= 1.');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100.');
        }
        if (!in_array($sortBy, self::ALLOWED_SORTS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported sortBy "%s".', $sortBy));
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported sortDirection "%s".', $sortDirection));
        }
    }

    public function normalizedSearch(): ?string
    {
        $search = $this->search === null ? '' : trim($this->search);

        return $search === '' ? null : $search;
    }

    public function normalizedSortDirection(): string
    {
        return strtolower($this->sortDirection);
    }
}
