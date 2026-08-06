<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class InitiateProductDocumentUploadRequest
{
    private function __construct(
        public string $documentTypeCode,
        public string $title,
        public string $accessCode,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public ?string $versionLabel,
        public ?string $languageCode,
        public int $displayOrder,
        public bool $isCad,
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
            documentTypeCode: RequestFields::requireString($body, 'documentTypeCode'),
            title: RequestFields::requireString($body, 'title'),
            accessCode: RequestFields::requireString($body, 'accessCode'),
            originalFileName: RequestFields::requireString($body, 'originalFileName'),
            contentType: RequestFields::requireString($body, 'contentType'),
            byteSize: $byteSize,
            versionLabel: RequestFields::stringOrNull($body, 'versionLabel'),
            languageCode: RequestFields::stringOrNull($body, 'languageCode'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            isCad: RequestFields::boolOrDefault($body, 'isCad', false),
        );
    }
}
