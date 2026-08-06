<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\GetRestrictedDocumentDownloadCommand;
use TriCab\Core\Product\Application\DTO\ProductDocumentDownloadResult;
use TriCab\Core\Product\Application\Exception\ProductDocumentNotFound;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\ValueObject\DocumentAccessCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

/**
 * Public documents return CDN/public URL. Restricted/authenticated documents
 * require an authenticated actor with PRODUCT.DOCUMENTS.MANAGE (Phase 9 simple gate)
 * and return a short-lived read ticket.
 */
final class GetRestrictedDocumentDownloadService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductDocumentRepository $documents,
        private readonly ProductBlobStore $blobStore,
    ) {
    }

    public function execute(ActorContext $actor, GetRestrictedDocumentDownloadCommand $command): ProductDocumentDownloadResult
    {
        $productPublicId = ProductPublicId::fromString($command->productPublicId);
        $product = $this->products->findByPublicId($productPublicId);
        if ($product === null) {
            throw ProductNotFound::forPublicId($command->productPublicId);
        }

        if (!$product->status()->isPublished()) {
            throw ProductFileUploadException::downloadForbidden('product is not published');
        }

        $document = $this->documents->findByPublicId($command->documentPublicId);
        if ($document === null || $document->productPublicId !== $productPublicId->toString()) {
            throw ProductDocumentNotFound::forPublicId($command->documentPublicId);
        }

        if ($document->uploadStatusCode === UploadStatusCode::DELETED) {
            throw ProductFileUploadException::downloadForbidden('document file has been deleted');
        }

        $access = DocumentAccessCode::fromString($document->accessCode);

        if ($access->isPublic()) {
            $url = $document->publicUrl;
            if ($url === null || $url === '') {
                throw ProductFileUploadException::downloadForbidden('public document has no public URL');
            }

            return new ProductDocumentDownloadResult(
                documentPublicId: $document->publicId,
                downloadUrl: $url,
                httpMethod: 'GET',
                requiredHeaders: [],
                expiresAtUtc: null,
                accessMode: DocumentAccessCode::PUBLIC,
                contentType: $document->contentType,
                originalFileName: $document->originalFileName,
            );
        }

        // restricted / authenticated
        if (!$command->actorHasDocumentsManagePermission) {
            throw ProductFileUploadException::downloadForbidden(
                'PRODUCT.DOCUMENTS.MANAGE permission required for restricted downloads',
            );
        }

        // ActorContext is required by the API boundary for authenticated/restricted downloads.
        if ($actor->userId < 1) {
            throw ProductFileUploadException::downloadForbidden('authenticated actor required');
        }

        if ($document->storageContainer === null || $document->storagePath === null) {
            throw ProductFileUploadException::downloadForbidden('document has no storage location');
        }

        $ticket = $this->blobStore->issueReadDownload(
            $document->storageContainer,
            $document->storagePath,
            $command->downloadExpiresInSeconds,
        );

        return new ProductDocumentDownloadResult(
            documentPublicId: $document->publicId,
            downloadUrl: $ticket->uploadUrl,
            httpMethod: $ticket->httpMethod,
            requiredHeaders: $ticket->requiredHeaders,
            expiresAtUtc: $ticket->expiresAtUtc,
            accessMode: $access->toString(),
            contentType: $document->contentType,
            originalFileName: $document->originalFileName,
        );
    }
}
