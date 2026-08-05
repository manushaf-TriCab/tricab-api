<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for POST /api/v1/admin/products/{productPublicId}/documents.
 * Metadata only — no binary upload (see docs: no blob upload endpoints in Phase 5).
 */
final readonly class AddProductDocumentRequest
{
    private function __construct(
        public string $documentTypeCode,
        public string $title,
        public string $storagePath,
        public string $accessCode,
        public ?string $versionLabel,
        public ?string $languageCode,
        public ?string $regionPublicId,
        public ?string $marketPublicId,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
        public int $displayOrder,
        public ?string $publicUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            documentTypeCode: RequestFields::requireString($body, 'documentTypeCode'),
            title: RequestFields::requireString($body, 'title'),
            storagePath: RequestFields::requireString($body, 'storagePath'),
            accessCode: RequestFields::requireString($body, 'accessCode'),
            versionLabel: RequestFields::stringOrNull($body, 'versionLabel'),
            languageCode: RequestFields::stringOrNull($body, 'languageCode'),
            regionPublicId: RequestFields::stringOrNull($body, 'regionPublicId'),
            marketPublicId: RequestFields::stringOrNull($body, 'marketPublicId'),
            effectiveFromUtc: RequestFields::stringOrNull($body, 'effectiveFromUtc'),
            effectiveToUtc: RequestFields::stringOrNull($body, 'effectiveToUtc'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            publicUrl: RequestFields::stringOrNull($body, 'publicUrl'),
        );
    }
}
