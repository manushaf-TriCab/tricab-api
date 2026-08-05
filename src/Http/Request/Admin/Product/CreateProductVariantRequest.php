<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;

/**
 * Validated body for POST /api/v1/admin/products/{productPublicId}/variants.
 */
final readonly class CreateProductVariantRequest
{
    private function __construct(
        public string $variantName,
        public string $statusCode,
        public int $displayOrder,
        public ?string $sku,
        public ?string $variantCode,
        public ?string $minimumOrderQuantity,
        public ?string $weightKg,
        public ?string $packagingDescription,
        public ?string $erpReference,
        public bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            variantName: RequestFields::requireString($body, 'variantName'),
            statusCode: RequestFields::stringOrDefault($body, 'statusCode', ProductStatusCode::DRAFT),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            sku: RequestFields::stringOrNull($body, 'sku'),
            variantCode: RequestFields::stringOrNull($body, 'variantCode'),
            minimumOrderQuantity: RequestFields::stringOrNull($body, 'minimumOrderQuantity'),
            weightKg: RequestFields::stringOrNull($body, 'weightKg'),
            packagingDescription: RequestFields::stringOrNull($body, 'packagingDescription'),
            erpReference: RequestFields::stringOrNull($body, 'erpReference'),
            isActive: RequestFields::boolOrDefault($body, 'isActive', true),
        );
    }
}
