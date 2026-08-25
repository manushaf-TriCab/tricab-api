<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductStyleAssignment
{
    /**
     * @param list<array{publicId: string, styleCode: string, displayName: string}> $ancestors
     */
    public function __construct(
        public string $productPublicId,
        public string $stylePublicId,
        public string $styleCode,
        public string $displayName,
        public ?string $parentPublicId,
        public ?string $parentStyleCode,
        public array $ancestors = [],
    ) {
    }
}
