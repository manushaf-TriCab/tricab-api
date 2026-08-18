<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/regions.
 */
final readonly class AssignRegionsRequest
{
    /**
     * @param list<string> $regionPublicIds
     */
    private function __construct(
        public array $regionPublicIds,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            regionPublicIds: RequestFields::listOfStrings($body, 'regionPublicIds'),
        );
    }
}
