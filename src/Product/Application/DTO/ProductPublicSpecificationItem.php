<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Public-safe specification highlight for customer product pages.
 * Values are display strings — never authoritative pricing decimals.
 */
final readonly class ProductPublicSpecificationItem
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $groupName,
        public ?string $displayValue,
        public ?string $unit,
    ) {
    }
}
