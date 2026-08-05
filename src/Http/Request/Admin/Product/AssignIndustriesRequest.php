<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/industries.
 */
final readonly class AssignIndustriesRequest
{
    /**
     * @param list<string> $industryPublicIds
     */
    private function __construct(
        public array $industryPublicIds,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            industryPublicIds: RequestFields::listOfStrings($body, 'industryPublicIds'),
        );
    }
}
