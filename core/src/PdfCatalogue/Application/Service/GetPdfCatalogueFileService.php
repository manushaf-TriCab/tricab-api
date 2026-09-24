<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Application\Service;

use TriCab\Core\PdfCatalogue\Application\Exception\PdfCatalogueNotFound;
use TriCab\Core\PdfCatalogue\Application\Exception\PdfCatalogueUnavailable;
use TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;

final class GetPdfCatalogueFileService
{
    public function __construct(
        private readonly PdfCatalogueRepository $catalogues,
        private readonly ProductBlobStore $blobs,
        private readonly string $blobContainer,
        private readonly int $downloadTtlSeconds = 300,
    ) {
    }

    /**
     * @return array{
     *   url: string,
     *   httpMethod: string,
     *   requiredHeaders: array<string, string>,
     *   expiresAtUtc: string|null,
     *   fileName: string,
     *   contentType: string,
     *   publicId: string
     * }
     */
    public function execute(string $publicId): array
    {
        $item = $this->catalogues->findActiveByPublicId($publicId);
        if ($item === null) {
            throw PdfCatalogueNotFound::forPublicId($publicId);
        }
        if (!$item->available || trim($item->documentBlobPath) === '') {
            throw PdfCatalogueUnavailable::documentMissing($publicId);
        }

        $ttl = max(60, $this->downloadTtlSeconds);
        try {
            $ticket = $this->blobs->issueReadDownload($this->blobContainer, $item->documentBlobPath, $ttl);
        } catch (\Throwable) {
            throw PdfCatalogueUnavailable::storage();
        }

        if (trim($ticket->uploadUrl) === '') {
            throw PdfCatalogueUnavailable::documentMissing($publicId);
        }

        return [
            'url' => $ticket->uploadUrl,
            'httpMethod' => $ticket->httpMethod !== '' ? $ticket->httpMethod : 'GET',
            'requiredHeaders' => $ticket->requiredHeaders,
            'expiresAtUtc' => $ticket->expiresAtUtc !== '' ? $ticket->expiresAtUtc : null,
            'fileName' => $item->fileName,
            'contentType' => $item->contentType,
            'publicId' => $item->publicId,
        ];
    }
}
