<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class ReplaceProductMediaUploadRequest
{
    private function __construct(
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $byteSize = RequestFields::intOrNull($body, 'byteSize');
        if ($byteSize === null) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'byteSize is required.');
        }

        return new self(
            originalFileName: RequestFields::requireString($body, 'originalFileName'),
            contentType: RequestFields::requireString($body, 'contentType'),
            byteSize: $byteSize,
        );
    }
}
