<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for POST /api/v1/admin/product-codes/assign.
 * productPublicId is carried in the body because this endpoint is not
 * nested under /products/{productPublicId}.
 */
final readonly class AssignProductCodeRequest
{
    /**
     * @param array<string, string> $segmentValues
     */
    private function __construct(
        public string $productPublicId,
        public string $ruleSetPublicId,
        public array $segmentValues,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            productPublicId: RequestFields::requireString($body, 'productPublicId'),
            ruleSetPublicId: RequestFields::requireString($body, 'ruleSetPublicId'),
            segmentValues: RequestFields::stringMap($body, 'segmentValues'),
        );
    }
}
