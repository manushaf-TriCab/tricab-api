<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PATCH /api/v1/admin/product-variants/{variantPublicId}.
 * Merge-patch semantics matching UpdateProductVariantCommand.
 */
final readonly class UpdateProductVariantRequest
{
    private function __construct(
        public string $expectedRowVersion,
        public ?string $variantName,
        public ?string $statusCode,
        public ?int $displayOrder,
        public ?string $sku,
        public ?string $variantCode,
        public ?string $minimumOrderQuantity,
        public ?string $weightKg,
        public ?string $packagingDescription,
        public ?string $erpReference,
        public ?bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            expectedRowVersion: RequestFields::requireString($body, 'expectedRowVersion'),
            variantName: RequestFields::stringOrNull($body, 'variantName'),
            statusCode: RequestFields::stringOrNull($body, 'statusCode'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder'),
            sku: RequestFields::stringOrNull($body, 'sku'),
            variantCode: RequestFields::stringOrNull($body, 'variantCode'),
            minimumOrderQuantity: RequestFields::stringOrNull($body, 'minimumOrderQuantity'),
            weightKg: RequestFields::stringOrNull($body, 'weightKg'),
            packagingDescription: RequestFields::stringOrNull($body, 'packagingDescription'),
            erpReference: RequestFields::stringOrNull($body, 'erpReference'),
            isActive: RequestFields::boolOrNull($body, 'isActive'),
        );
    }
}
