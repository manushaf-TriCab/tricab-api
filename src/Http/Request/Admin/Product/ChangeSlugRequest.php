<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for POST /api/v1/admin/products/{productPublicId}/slugs.
 */
final readonly class ChangeSlugRequest
{
    private function __construct(
        public string $newSlug,
        public ?string $regionPublicId,
        public bool $isPrimary,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            newSlug: RequestFields::requireString($body, 'newSlug'),
            regionPublicId: RequestFields::stringOrNull($body, 'regionPublicId'),
            isPrimary: RequestFields::boolOrDefault($body, 'isPrimary', true),
        );
    }
}
