<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Application\DTO;

final readonly class PdfCatalogueItem
{
    /**
     * @param list<string> $regions
     */
    public function __construct(
        public string $publicId,
        public string $title,
        public string $issue,
        public array $regions,
        public int $sortOrder,
        public bool $available,
        public string $seedKey,
        public string $documentBlobPath,
        public ?string $thumbnailBlobPath = null,
        public string $fileName = 'catalogue.pdf',
        public string $contentType = 'application/pdf',
    ) {
    }
}
