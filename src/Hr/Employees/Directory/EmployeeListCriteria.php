<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use InvalidArgumentException;

/**
 * What GET /api/v1/hr/employees asks for. Invalid values throw
 * InvalidArgumentException, which the action answers as 422 VALIDATION_FAILED.
 */
final readonly class EmployeeListCriteria
{
    public const STATUS_CURRENT = 'current';
    public const STATUS_PAST = 'past';
    public const STATUS_ALL = 'all';

    public const SORT_NAME = 'name';
    public const SORT_CODE = 'code';
    public const SORT_DEPARTMENT = 'department';
    public const SORT_FACTORY = 'factory';
    public const SORT_MANAGER = 'manager';
    public const SORT_DATE_HIRED = 'dateHired';
    public const SORT_LAST_SEEN = 'lastSeen';

    public const ALLOWED_STATUS = [self::STATUS_CURRENT, self::STATUS_PAST, self::STATUS_ALL];
    public const ALLOWED_SORT = [
        self::SORT_NAME, self::SORT_CODE, self::SORT_DEPARTMENT, self::SORT_FACTORY,
        self::SORT_MANAGER, self::SORT_DATE_HIRED, self::SORT_LAST_SEEN,
    ];

    public function __construct(
        public int $page = 1,
        public int $pageSize = 25,
        public string $status = self::STATUS_CURRENT,
        public ?string $department = null,
        public ?string $factory = null,
        public ?string $manager = null,
        public ?string $search = null,
        public string $sortBy = self::SORT_NAME,
        public string $sortDirection = 'asc',
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException('page must be >= 1.');
        }
        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100.');
        }
        if (!in_array($this->status, self::ALLOWED_STATUS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported status "%s".', $this->status));
        }
        if (!in_array($this->sortBy, self::ALLOWED_SORT, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported sortBy "%s".', $this->sortBy));
        }
        if (!in_array(strtolower($this->sortDirection), ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported sortDirection "%s".', $this->sortDirection));
        }
    }

    public function descending(): bool
    {
        return strtolower($this->sortDirection) === 'desc';
    }
}
