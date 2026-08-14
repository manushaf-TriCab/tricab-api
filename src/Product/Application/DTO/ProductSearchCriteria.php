<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

use InvalidArgumentException;

final readonly class ProductSearchCriteria
{
    /**
     * @param list<string> $statusCodes Optional server-authorized status scope.
     */
    public function __construct(
        public string $query,
        public int $page = 1,
        public int $pageSize = 25,
        public ?string $regionCode = null,
        public bool $publishedOnly = true,
        public array $statusCodes = [],
    ) {
        if (trim($this->query) === '') {
            throw new InvalidArgumentException('query must not be empty.');
        }
        if ($this->page < 1) {
            throw new InvalidArgumentException('page must be >= 1.');
        }
        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100.');
        }
        foreach ($this->statusCodes as $statusCode) {
            if (trim($statusCode) === '') {
                throw new InvalidArgumentException('statusCodes must contain non-empty strings.');
            }
        }
    }

    public function normalizedQuery(): string
    {
        return trim($this->query);
    }

    /** @return list<string> */
    public function normalizedStatusCodes(): array
    {
        $codes = array_map(
            static fn (string $statusCode): string => strtolower(trim($statusCode)),
            $this->statusCodes,
        );

        return array_values(array_unique($codes));
    }
}
