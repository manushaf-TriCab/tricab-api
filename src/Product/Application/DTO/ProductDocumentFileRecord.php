<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Rich document row for upload lifecycle (admin / command side).
 */
final readonly class ProductDocumentFileRecord
{
    public function __construct(
        public string $publicId,
        public string $productPublicId,
        public string $documentTypeCode,
        public string $title,
        public ?string $versionLabel,
        public ?string $languageCode,
        public ?string $publicUrl,
        public string $accessCode,
        public int $displayOrder,
        public string $uploadStatusCode,
        public string $scanStatusCode,
        public ?int $byteSize,
        public ?string $contentType,
        public ?string $originalFileName,
        public ?string $fileExtension,
        public ?string $storagePath,
        public ?string $storageContainer,
        public ?string $pendingStoragePath = null,
        public ?string $pendingStorageContainer = null,
        public ?string $previousStoragePath = null,
    ) {
    }

    public function toSummary(bool $includeStoragePath = false): ProductDocumentSummary
    {
        return new ProductDocumentSummary(
            publicId: $this->publicId,
            documentTypeCode: $this->documentTypeCode,
            title: $this->title,
            versionLabel: $this->versionLabel,
            languageCode: $this->languageCode,
            publicUrl: $this->publicUrl,
            accessCode: $this->accessCode,
            displayOrder: $this->displayOrder,
            uploadStatusCode: $this->uploadStatusCode,
            scanStatusCode: $this->scanStatusCode,
            byteSize: $this->byteSize,
            contentType: $this->contentType,
            originalFileName: $this->originalFileName,
            fileExtension: $this->fileExtension,
            storagePath: $includeStoragePath ? $this->storagePath : null,
        );
    }
}
