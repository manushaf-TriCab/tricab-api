<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for POST /api/v1/admin/product-variants/{variantPublicId}/restore.
 * A single endpoint covers both restore paths from RestoreProductVariantCommand
 * — "fromDeletion" selects restore-from-deletion (true) vs restore-from-archive
 * (false, default) since Product's admin API keeps those as two endpoints but
 * Variant's minimum viable surface uses one.
 */
final readonly class RestoreProductVariantRequest
{
    private function __construct(
        public string $expectedRowVersion,
        public bool $fromDeletion,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            expectedRowVersion: RequestFields::requireString($body, 'expectedRowVersion'),
            fromDeletion: RequestFields::boolOrDefault($body, 'fromDeletion', false),
        );
    }
}
