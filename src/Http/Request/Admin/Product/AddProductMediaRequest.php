<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for POST /api/v1/admin/products/{productPublicId}/media.
 * Metadata only — no binary upload; storagePath/publicUrl point at content
 * already staged elsewhere (see docs: no blob upload endpoints in Phase 5).
 */
final readonly class AddProductMediaRequest
{
    private function __construct(
        public string $mediaCategoryCode,
        public string $mediaTypeCode,
        public string $title,
        public string $storagePath,
        public ?string $storageContainer,
        public ?string $contentType,
        public ?string $fileExtension,
        public ?int $byteSize,
        public ?string $altText,
        public ?string $caption,
        public int $displayOrder,
        public bool $isPrimary,
        public ?string $publicUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            mediaCategoryCode: RequestFields::requireString($body, 'mediaCategoryCode'),
            mediaTypeCode: RequestFields::requireString($body, 'mediaTypeCode'),
            title: RequestFields::requireString($body, 'title'),
            storagePath: RequestFields::requireString($body, 'storagePath'),
            storageContainer: RequestFields::stringOrNull($body, 'storageContainer'),
            contentType: RequestFields::stringOrNull($body, 'contentType'),
            fileExtension: RequestFields::stringOrNull($body, 'fileExtension'),
            byteSize: RequestFields::intOrNull($body, 'byteSize'),
            altText: RequestFields::stringOrNull($body, 'altText'),
            caption: RequestFields::stringOrNull($body, 'caption'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            isPrimary: RequestFields::boolOrDefault($body, 'isPrimary', false),
            publicUrl: RequestFields::stringOrNull($body, 'publicUrl'),
        );
    }
}
