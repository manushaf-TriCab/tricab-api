<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\DTO;

use InvalidArgumentException;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteCategory;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteInteractionType;

final readonly class LogNoteListCriteria
{
    public const SORT_CREATED_AT = 'createdAt';
    public const SORT_DESC = 'desc';
    public const SORT_ASC = 'asc';

    public function __construct(
        public int $page = 1,
        public int $pageSize = 25,
        public ?string $customerPublicId = null,
        public ?string $customerCode = null,
        public ?string $interactionType = null,
        public ?string $category = null,
        public ?string $createdBy = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $search = null,
        public ?string $country = null,
        public ?string $territory = null,
        public string $sortBy = self::SORT_CREATED_AT,
        public string $sortDirection = self::SORT_DESC,
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException('page must be >= 1.');
        }

        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100.');
        }

        if ($this->sortBy !== self::SORT_CREATED_AT) {
            throw new InvalidArgumentException(sprintf('Unsupported sortBy "%s".', $this->sortBy));
        }

        $direction = strtolower($this->sortDirection);
        if ($direction !== self::SORT_ASC && $direction !== self::SORT_DESC) {
            throw new InvalidArgumentException(sprintf('Unsupported sortDirection "%s".', $this->sortDirection));
        }

        if ($this->interactionType !== null) {
            LogNoteInteractionType::fromString($this->interactionType);
        }

        if ($this->category !== null) {
            LogNoteCategory::fromString($this->category);
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
