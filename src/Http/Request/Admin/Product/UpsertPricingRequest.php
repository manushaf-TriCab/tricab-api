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
        public string $baseCost,
        public string $minimumQuantity,
        public ?string $priceListPublicId,
        public ?string $regionPublicId,
        public ?string $marketPublicId,
        public ?string $discountPercent,
        public bool $taxInclusive,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
        public ?string $variantPublicId,
        public ?string $priceSourceCode,
        public bool $createRevision,
        public ?string $pricingPublicId,
        public ?string $expectedRowVersion,
        public ?string $unitOfMeasurePublicId,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $baseCost = RequestFields::stringOrNull($body, 'baseCost')
            ?? RequestFields::requireString($body, 'unitPrice');

        return new self(
            currencyCode: RequestFields::requireString($body, 'currencyCode'),
            baseCost: $baseCost,
            minimumQuantity: RequestFields::stringOrDefault($body, 'minimumQuantity', '1'),
            priceListPublicId: RequestFields::stringOrNull($body, 'priceListPublicId'),
            regionPublicId: RequestFields::stringOrNull($body, 'regionPublicId'),
            marketPublicId: RequestFields::stringOrNull($body, 'marketPublicId'),
            discountPercent: RequestFields::stringOrNull($body, 'discountPercent'),
            taxInclusive: RequestFields::boolOrDefault($body, 'taxInclusive', false),
            effectiveFromUtc: RequestFields::stringOrNull($body, 'effectiveFromUtc'),
            effectiveToUtc: RequestFields::stringOrNull($body, 'effectiveToUtc'),
            variantPublicId: RequestFields::stringOrNull($body, 'variantPublicId'),
            priceSourceCode: RequestFields::stringOrNull($body, 'priceSourceCode'),
            createRevision: RequestFields::boolOrDefault($body, 'createRevision', false),
            pricingPublicId: RequestFields::stringOrNull($body, 'pricingPublicId'),
            expectedRowVersion: RequestFields::stringOrNull($body, 'expectedRowVersion'),
            unitOfMeasurePublicId: RequestFields::stringOrNull($body, 'unitOfMeasurePublicId'),
        );
    }
}
