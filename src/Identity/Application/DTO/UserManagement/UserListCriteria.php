<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserListCriteria
{
    public const SORT_EMAIL = 'email';
    public const SORT_DISPLAY_NAME = 'displayName';
    public const SORT_STATUS = 'status';
    public const SORT_CREATED_AT = 'createdAt';
    public const SORT_UPDATED_AT = 'updatedAt';
    public const SORT_LAST_LOGIN_AT = 'lastLoginAt';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    private const ALLOWED_SORT = [
        self::SORT_EMAIL,
        self::SORT_DISPLAY_NAME,
        self::SORT_STATUS,
        self::SORT_CREATED_AT,
        self::SORT_UPDATED_AT,
        self::SORT_LAST_LOGIN_AT,
    ];

    public function __construct(
        public int $page = 1,
        public int $pageSize = 25,
        public ?string $search = null,
        public ?string $status = null,
        public ?string $roleCode = null,
        public ?string $providerCode = null,
        public string $sortBy = self::SORT_CREATED_AT,
        public string $sortDirection = self::SORT_DESC,
    ) {
        if ($this->page < 1) {
            throw new \InvalidArgumentException('page must be >= 1.');
        }

        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw new \InvalidArgumentException('pageSize must be between 1 and 100.');
        }

        if (!in_array($this->sortBy, self::ALLOWED_SORT, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported sortBy "%s".', $this->sortBy));
        }

        $direction = strtolower($this->sortDirection);
        if ($direction !== self::SORT_ASC && $direction !== self::SORT_DESC) {
            throw new \InvalidArgumentException(sprintf('Unsupported sortDirection "%s".', $this->sortDirection));
        }

        // normalize via reconstruction is awkward on readonly; expose normalized getters instead
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
