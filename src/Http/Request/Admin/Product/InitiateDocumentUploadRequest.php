<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class InitiateDocumentUploadRequest
{
    public function __construct(
        public string $fileName,
        public string $contentType,
        public int $byteSize,
        public string $documentTypeCode,
        public string $title,
        public string $accessCode,
        public ?string $versionLabel,
        public ?string $languageCode,
        public int $displayOrder,
        public bool $isCad,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            fileName: RequestFields::requireString($body, 'fileName'),
            contentType: RequestFields::requireString($body, 'contentType'),
            byteSize: RequestFields::requireInt($body, 'byteSize'),
            documentTypeCode: RequestFields::requireString($body, 'documentTypeCode'),
            title: RequestFields::requireString($body, 'title'),
            accessCode: RequestFields::requireString($body, 'accessCode'),
            versionLabel: RequestFields::stringOrNull($body, 'versionLabel'),
            languageCode: RequestFields::stringOrNull($body, 'languageCode'),
            displayOrder: RequestFields::intOrDefault($body, 'displayOrder', 0),
            isCad: RequestFields::boolOrDefault($body, 'isCad', false),
        );
    }
}
