<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

use InvalidArgumentException;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;

final readonly class QuoteOrderSearchCriteria
{
    public function __construct(
        public int $page = 1,
        public int $pageSize = 25,
        public ?string $reference = null,
        public ?string $customerCode = null,
        public ?string $representative = null,
        public ?string $from = null,
        public ?string $to = null,
        public string $type = QuoteOrderType::ALL,
        public ?string $statusCode = null,
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException('page must be >= 1.');
        }

        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100.');
        }

        QuoteOrderType::fromString($this->type);
    }

    public function typeFilter(): QuoteOrderType
    {
        return QuoteOrderType::fromString($this->type);
    }
}
