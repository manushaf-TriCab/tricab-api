<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Application\DTO;

final readonly class PdfCatalogueListResult
{
    /**
     * @param list<PdfCatalogueItem> $items
     */
    public function __construct(
        public array $items,
        public int $count,
    ) {
    }
}
