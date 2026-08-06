<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class Initiate360FrameUploadRequest
{
    private function __construct(
        public int $frameNumber,
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
        $frameNumber = RequestFields::intOrNull($body, 'frameNumber');
        $byteSize = RequestFields::intOrNull($body, 'byteSize');
        if ($frameNumber === null || $byteSize === null) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'frameNumber and byteSize are required.');
        }

        return new self(
            frameNumber: $frameNumber,
            originalFileName: RequestFields::requireString($body, 'originalFileName'),
            contentType: RequestFields::requireString($body, 'contentType'),
            byteSize: $byteSize,
        );
    }
}
