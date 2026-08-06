<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/pricing.
 */
final readonly class UpsertPricingRequest
{
    private function __construct(
        public string $currencyCode,
        public string $unitPrice,
        public string $minimumQuantity,
        public ?string $priceListPublicId,
        public ?string $regionPublicId,
        public ?string $marketPublicId,
        public ?string $discountPercent,
        public bool $taxInclusive,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
        public ?string $variantPublicId,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            currencyCode: RequestFields::requireString($body, 'currencyCode'),
            unitPrice: RequestFields::requireString($body, 'unitPrice'),
            minimumQuantity: RequestFields::stringOrDefault($body, 'minimumQuantity', '1'),
            priceListPublicId: RequestFields::stringOrNull($body, 'priceListPublicId'),
            regionPublicId: RequestFields::stringOrNull($body, 'regionPublicId'),
            marketPublicId: RequestFields::stringOrNull($body, 'marketPublicId'),
            discountPercent: RequestFields::stringOrNull($body, 'discountPercent'),
            taxInclusive: RequestFields::boolOrDefault($body, 'taxInclusive', false),
            effectiveFromUtc: RequestFields::stringOrNull($body, 'effectiveFromUtc'),
            effectiveToUtc: RequestFields::stringOrNull($body, 'effectiveToUtc'),
            variantPublicId: RequestFields::stringOrNull($body, 'variantPublicId'),
        );
    }
}
