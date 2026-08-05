<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for:
 *  - PUT /api/v1/admin/products/{productPublicId}/specifications
 *  - PUT /api/v1/admin/product-variants/{variantPublicId}/specifications
 *
 * Each item mirrors AssignProductSpecificationsCommand's SpecValueInput shape;
 * per-value type/scope validation is left to SpecificationCommandAssembler.
 */
final readonly class AssignSpecificationsRequest
{
    /**
     * @param list<array<string, mixed>> $values
     */
    private function __construct(
        public array $values,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $values = RequestFields::listOfArrays($body, 'values', required: true);
        foreach ($values as $index => $value) {
            RequestFields::requireItemString($value, 'definitionPublicId', sprintf('values[%d]', $index));
        }

        return new self(values: $values);
    }
}
