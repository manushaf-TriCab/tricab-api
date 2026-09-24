<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Contract;

use TriCab\Core\PdfCatalogue\Application\DTO\PdfCatalogueItem;

interface PdfCatalogueRepository
{
    /**
     * Active catalogues only, ordered by sortOrder then title.
     *
     * @return list<PdfCatalogueItem>
     */
    public function listActive(): array;

    public function findActiveByPublicId(string $publicId): ?PdfCatalogueItem;
}
