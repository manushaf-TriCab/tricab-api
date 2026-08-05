<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/markets.
 */
final readonly class AssignMarketsRequest
{
    /**
     * @param list<string> $regionPublicIds
     * @param list<string> $marketPublicIds
     */
    private function __construct(
        public array $regionPublicIds,
        public array $marketPublicIds,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            regionPublicIds: RequestFields::listOfStrings($body, 'regionPublicIds'),
            marketPublicIds: RequestFields::listOfStrings($body, 'marketPublicIds'),
        );
    }
}
