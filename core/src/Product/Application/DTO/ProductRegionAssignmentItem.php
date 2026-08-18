<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductRegionAssignmentItem
{
    public function __construct(
        public string $publicId,
        public string $code,
        public string $displayName,
        public ?string $flag,
        public bool $selected,
    ) {
    }
}
