<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/applications.
 */
final readonly class AssignApplicationsRequest
{
    /**
     * @param list<string> $applicationPublicIds
     */
    private function __construct(
        public array $applicationPublicIds,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            applicationPublicIds: RequestFields::listOfStrings($body, 'applicationPublicIds'),
        );
    }
}
