<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class CompleteProductMediaUploadRequest
{
    private function __construct(
        public ?string $binaryContent,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $binaryBase64 = RequestFields::stringOrNull($body, 'binaryBase64');
        $binaryContent = null;
        if ($binaryBase64 !== null) {
            $decoded = base64_decode($binaryBase64, true);
            if ($decoded === false) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'binaryBase64 is invalid.');
            }
            $binaryContent = $decoded;
        }

        return new self(binaryContent: $binaryContent);
    }
}
