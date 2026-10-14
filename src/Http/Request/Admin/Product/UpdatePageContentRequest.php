<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/page-content.
 * All three fields plus `expectedVersion` (the `version` the administrator loaded; null when the product had no settings row)
 * are required, so a save always states the complete visibility + note state and never silently overwrites another administrator.
 */
final readonly class UpdatePageContentRequest
{
    private function __construct(
        public ProductPageContent $content,
        public ?string $expectedVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        foreach (['showProductMarking', 'coreColoursNote', 'showCoreColoursNote', 'expectedVersion'] as $key) {
            if (!array_key_exists($key, $body)) {
                throw InvalidProductValue::for('pageContent', $key . ' is required');
            }
        }

        $expected = $body['expectedVersion'];
        if ($expected !== null && (!is_string($expected) || $expected === '' || strlen($expected) > 64)) {
            throw InvalidProductValue::for('expectedVersion', 'expected a version string or null');
        }

        return new self(ProductPageContent::fromInput(
            $body['showProductMarking'],
            $body['coreColoursNote'],
            $body['showCoreColoursNote'],
        ), $expected);
    }
}
