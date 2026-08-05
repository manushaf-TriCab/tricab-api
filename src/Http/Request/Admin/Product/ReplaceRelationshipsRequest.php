<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/relationships.
 * Matches ReplaceProductRelationshipsCommand: a "type" plus the full replacement
 * set of "items" for that relationship type.
 */
final readonly class ReplaceRelationshipsRequest
{
    /**
     * @param list<array<string, mixed>> $items
     */
    private function __construct(
        public string $type,
        public array $items,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $items = RequestFields::listOfArrays($body, 'items');
        foreach ($items as $index => $item) {
            RequestFields::requireItemString($item, 'relatedProductPublicId', sprintf('items[%d]', $index));
        }

        return new self(
            type: RequestFields::requireString($body, 'type'),
            items: $items,
        );
    }
}
