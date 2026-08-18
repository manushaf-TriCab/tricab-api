<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class CatalogueProductPlacementRecord
{
    /**
     * @param list<string> $breadcrumbNames
     */
    public function __construct(
        public string $nodePublicId,
        public string $nodeName,
        public string $nodeSlug,
        public string $tabPublicId,
        public string $tabName,
        public string $tabSlug,
        public int $displayOrder,
        public bool $isFeatured,
        public bool $isActive,
        public array $breadcrumbNames = [],
    ) {
    }
}
