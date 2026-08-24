<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/pricing/default-unit.
 */
final readonly class SetProductDefaultPricingUnitRequest
{
    private function __construct(
        public ?string $pricingUnitPublicId,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            pricingUnitPublicId: RequestFields::stringOrNull($body, 'pricingUnitPublicId'),
        );
    }
}
