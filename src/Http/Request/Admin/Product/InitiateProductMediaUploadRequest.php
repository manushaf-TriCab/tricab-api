<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class InitiateProductMediaUploadRequest
{
    private function __construct(
        public string $mediaCategoryCode,
        public string $mediaTypeCode,
        public string $title,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
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
        $byteSize = RequestFields::intOrNull($body, 'byteSize');
        if ($byteSize === null) {
            throw \TriCab\Api\Http\Exception\HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'byteSize is required.');
        }

        return new self(
            mediaCategoryCode: RequestFields::requireString($body, 'mediaCategoryCode'),
            mediaTypeCode: RequestFields::requireString($body, 'mediaTypeCode'),
            title: RequestFields::requireString($body, 'title'),
            originalFileName: RequestFields::requireString($body, 'originalFileName'),
            contentType: RequestFields::requireString($body, 'contentType'),
            byteSize: $byteSize,
            altText: RequestFields::stringOrNull($body, 'altText'),
            caption: RequestFields::stringOrNull($body, 'caption'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            isPrimary: RequestFields::boolOrDefault($body, 'isPrimary', false),
        );
    }
}
