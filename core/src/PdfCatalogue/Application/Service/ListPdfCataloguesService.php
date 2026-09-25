<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Application\Service;

use TriCab\Core\PdfCatalogue\Application\DTO\PdfCatalogueListResult;
use TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository;

final class ListPdfCataloguesService
{
    public function __construct(
        private readonly PdfCatalogueRepository $catalogues,
    ) {
    }

    public function execute(): PdfCatalogueListResult
    {
        $items = $this->catalogues->listActive();

        return new PdfCatalogueListResult($items, count($items));
    }
}
