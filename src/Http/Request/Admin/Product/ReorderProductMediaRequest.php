<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for POST /api/v1/admin/products/{productPublicId}/media/reorder.
 */
final readonly class ReorderProductMediaRequest
{
    /**
     * @param list<string> $orderedMediaPublicIds
     */
    private function __construct(
        public array $orderedMediaPublicIds,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            orderedMediaPublicIds: RequestFields::listOfStrings($body, 'orderedMediaPublicIds', required: true),
        );
    }
}
