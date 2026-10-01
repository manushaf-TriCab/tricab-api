<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DescriptionImagePresentation;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/page-media-layout.
 */
final readonly class UpdatePageMediaLayoutRequest
{
    private function __construct(
        public DescriptionImagePresentation $presentation,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        if (!array_key_exists('descriptionImageWidth', $body) || !array_key_exists('descriptionImageAlignment', $body)) {
            throw InvalidProductValue::for('pageMediaLayout', 'descriptionImageWidth and descriptionImageAlignment are required');
        }

        $width = $body['descriptionImageWidth'];
        $alignment = $body['descriptionImageAlignment'];
        if ($width !== null && !is_string($width)) {
            throw InvalidProductValue::for('descriptionImageWidth', 'expected a string');
        }
        if ($alignment !== null && !is_string($alignment)) {
            throw InvalidProductValue::for('descriptionImageAlignment', 'expected a string');
        }

        return new self(DescriptionImagePresentation::fromInput(
            is_string($width) ? $width : null,
            is_string($alignment) ? $alignment : null,
        ));
    }
}
