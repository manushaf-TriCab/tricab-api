<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;

/**
 * Validated body for POST /api/v1/admin/products.
 */
final readonly class CreateProductRequest
{
    private function __construct(
        public string $productName,
        public ?string $internalName,
        public ?string $internalCode,
        public ?string $shortDescription,
        public ?string $fullDescription,
        public ?string $legacyProductId,
        public ?string $erpReference,
        public bool $isFeatured,
        public ?string $familyPublicId,
        public ?string $categoryPublicId,
        public ?string $seriesPublicId,
        public string $statusCode,
        public string $lifecycleStatusCode,
        public ?string $publishAtUtc,
        public ?string $unpublishAtUtc,
        public ?string $discontinueAtUtc,
        public bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            productName: RequestFields::requireString($body, 'productName'),
            internalName: RequestFields::stringOrNull($body, 'internalName'),
            internalCode: RequestFields::stringOrNull($body, 'internalCode'),
            shortDescription: RequestFields::stringOrNull($body, 'shortDescription'),
            fullDescription: RequestFields::stringOrNull($body, 'fullDescription'),
            legacyProductId: RequestFields::stringOrNull($body, 'legacyProductId'),
            erpReference: RequestFields::stringOrNull($body, 'erpReference'),
            isFeatured: RequestFields::boolOrDefault($body, 'isFeatured', false),
            familyPublicId: RequestFields::stringOrNull($body, 'familyPublicId'),
            categoryPublicId: RequestFields::stringOrNull($body, 'categoryPublicId'),
            seriesPublicId: RequestFields::stringOrNull($body, 'seriesPublicId'),
            statusCode: RequestFields::stringOrDefault($body, 'statusCode', ProductStatusCode::DRAFT),
            lifecycleStatusCode: RequestFields::stringOrDefault(
                $body,
                'lifecycleStatusCode',
                ProductLifecycleStatusCode::CONCEPT,
            ),
            publishAtUtc: RequestFields::stringOrNull($body, 'publishAtUtc'),
            unpublishAtUtc: RequestFields::stringOrNull($body, 'unpublishAtUtc'),
            discontinueAtUtc: RequestFields::stringOrNull($body, 'discontinueAtUtc'),
            isActive: RequestFields::boolOrDefault($body, 'isActive', true),
        );
    }
}
