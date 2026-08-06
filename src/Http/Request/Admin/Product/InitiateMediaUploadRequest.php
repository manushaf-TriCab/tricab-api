<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class InitiateMediaUploadRequest
{
    public function __construct(
        public string $fileName,
        public string $contentType,
        public int $byteSize,
        public string $mediaCategoryCode,
        public string $mediaTypeCode,
        public string $title,
        public ?string $altText,
        public ?string $caption,
        public int $displayOrder,
        public bool $isPrimary,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            fileName: RequestFields::requireString($body, 'fileName'),
            contentType: RequestFields::requireString($body, 'contentType'),
            byteSize: RequestFields::requireInt($body, 'byteSize'),
            mediaCategoryCode: RequestFields::requireString($body, 'mediaCategoryCode'),
            mediaTypeCode: RequestFields::requireString($body, 'mediaTypeCode'),
            title: RequestFields::requireString($body, 'title'),
            altText: RequestFields::stringOrNull($body, 'altText'),
            caption: RequestFields::stringOrNull($body, 'caption'),
            displayOrder: RequestFields::intOrDefault($body, 'displayOrder', 0),
            isPrimary: RequestFields::boolOrDefault($body, 'isPrimary', false),
        );
    }
}
