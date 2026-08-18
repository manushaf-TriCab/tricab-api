<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertCatalogueNodeCommand
{
    /**
     * @param list<string>|null $regionCodes null = leave unchanged on update; [] = global
     */
    public function __construct(
        public string $tabPublicId,
        public string $nodeName,
        public string $nodeCode,
        public string $slug,
        public ?string $parentPublicId = null,
        public ?string $description = null,
        public string $nodeTypeCode = 'category',
        public ?string $imageUrl = null,
        public ?string $imageAltText = null,
        public ?string $imageMediaPublicId = null,
        public int $displayOrder = 0,
        public bool $isActive = true,
        public bool $isPublic = true,
        public ?string $seoTitle = null,
        public ?string $seoDescription = null,
        public ?array $regionCodes = null,
        public ?string $expectedRowVersion = null,
        public ?string $nodePublicId = null,
    ) {
    }
}
