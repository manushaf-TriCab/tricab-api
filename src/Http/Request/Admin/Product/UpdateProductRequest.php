<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PATCH /api/v1/admin/products/{productPublicId}.
 * Merge-patch semantics: null (or omitted) fields leave the current value
 * unchanged, matching UpdateProductCommand.
 */
final readonly class UpdateProductRequest
{
    private function __construct(
        public string $expectedRowVersion,
        public ?string $productName,
        public ?string $internalName,
        public ?string $internalCode,
        public ?string $shortDescription,
        public ?string $fullDescription,
        public ?string $legacyProductId,
        public ?string $erpReference,
        public ?bool $isFeatured,
        public ?string $publishAtUtc,
        public ?string $unpublishAtUtc,
        public ?string $discontinueAtUtc,
        public ?bool $isActive,
        public bool $updateClassification,
        public ?string $familyPublicId,
        public ?string $categoryPublicId,
        public ?string $seriesPublicId,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            expectedRowVersion: RequestFields::requireString($body, 'expectedRowVersion'),
            productName: RequestFields::stringOrNull($body, 'productName'),
            internalName: RequestFields::stringOrNull($body, 'internalName'),
            internalCode: RequestFields::stringOrNull($body, 'internalCode'),
            shortDescription: RequestFields::stringOrNull($body, 'shortDescription'),
            fullDescription: RequestFields::stringOrNull($body, 'fullDescription'),
            legacyProductId: RequestFields::stringOrNull($body, 'legacyProductId'),
            erpReference: RequestFields::stringOrNull($body, 'erpReference'),
            isFeatured: RequestFields::boolOrNull($body, 'isFeatured'),
            publishAtUtc: RequestFields::stringOrNull($body, 'publishAtUtc'),
            unpublishAtUtc: RequestFields::stringOrNull($body, 'unpublishAtUtc'),
            discontinueAtUtc: RequestFields::stringOrNull($body, 'discontinueAtUtc'),
            isActive: RequestFields::boolOrNull($body, 'isActive'),
            updateClassification: RequestFields::boolOrDefault($body, 'updateClassification', false),
            familyPublicId: RequestFields::stringOrNull($body, 'familyPublicId'),
            categoryPublicId: RequestFields::stringOrNull($body, 'categoryPublicId'),
            seriesPublicId: RequestFields::stringOrNull($body, 'seriesPublicId'),
        );
    }
}
