<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

use InvalidArgumentException;

final readonly class ProductListCriteria
{
    public const SORT_NAME = 'name';
    public const SORT_PRODUCT_CODE = 'productCode';
    public const SORT_CATEGORY = 'category';
    public const SORT_SERIES = 'series';
    public const SORT_STATUS = 'status';
    public const SORT_LIFECYCLE = 'lifecycle';
    public const SORT_CREATED_AT = 'createdAt';
    public const SORT_UPDATED_AT = 'updatedAt';
    public const SORT_DISPLAY_ORDER = 'displayOrder';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    private const ALLOWED_SORT = [
        self::SORT_NAME,
        self::SORT_PRODUCT_CODE,
        self::SORT_CATEGORY,
        self::SORT_SERIES,
        self::SORT_STATUS,
        self::SORT_LIFECYCLE,
        self::SORT_CREATED_AT,
        self::SORT_UPDATED_AT,
        self::SORT_DISPLAY_ORDER,
    ];

    public function __construct(
        public int $page = 1,
        public int $pageSize = 25,
        public ?string $search = null,
        public ?string $familyPublicId = null,
        public ?string $categoryPublicId = null,
        public ?string $seriesPublicId = null,
        public ?string $regionPublicId = null,
        public ?string $marketPublicId = null,
        public ?string $industryPublicId = null,
        public ?string $applicationPublicId = null,
        public ?string $statusCode = null,
        public ?string $lifecycleStatusCode = null,
        public ?bool $hasVariants = null,
        public ?bool $hasDocuments = null,
        public ?bool $hasMedia = null,
        public ?bool $hasPricing = null,
        public ?bool $missingRequiredInformation = null,
        public ?bool $missingRegion = null,
        public ?bool $missingSeo = null,
        public ?bool $missingSpecifications = null,
        public ?bool $publicationBlockers = null,
        public ?bool $isActive = null,
        /** When true, filter to archived status only; when false, exclude archived; null = no filter. */
        public ?bool $isArchived = null,
        /** Soft-deleted rows are excluded unless this is true. */
        public bool $includeDeleted = false,
        public ?string $updatedFromUtc = null,
        public ?string $updatedToUtc = null,
        public string $sortBy = self::SORT_UPDATED_AT,
        public string $sortDirection = self::SORT_DESC,
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException('page must be >= 1.');
        }

        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100.');
        }

        if (!in_array($this->sortBy, self::ALLOWED_SORT, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported sortBy "%s".', $this->sortBy));
        }

        $direction = strtolower($this->sortDirection);
        if ($direction !== self::SORT_ASC && $direction !== self::SORT_DESC) {
            throw new InvalidArgumentException(sprintf('Unsupported sortDirection "%s".', $this->sortDirection));
        }
    }

    public function normalizedSortDirection(): string
    {
        return strtolower($this->sortDirection) === self::SORT_ASC ? self::SORT_ASC : self::SORT_DESC;
    }

    public function normalizedSearch(): ?string
    {
        if ($this->search === null) {
            return null;
        }

        $trimmed = trim($this->search);

        return $trimmed === '' ? null : $trimmed;
    }
}
