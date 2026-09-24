<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Application\Service;

use TriCab\Core\PdfCatalogue\Application\DTO\PdfCatalogueItem;
use TriCab\Core\PdfCatalogue\Application\Exception\PdfCatalogueNotFound;
use TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository;

final class GetPdfCatalogueService
{
    public function __construct(
        private readonly PdfCatalogueRepository $catalogues,
    ) {
    }

    public function execute(string $publicId): PdfCatalogueItem
    {
        $item = $this->catalogues->findActiveByPublicId($publicId);
        if ($item === null) {
            throw PdfCatalogueNotFound::forPublicId($publicId);
        }

        return $item;
    }
}
