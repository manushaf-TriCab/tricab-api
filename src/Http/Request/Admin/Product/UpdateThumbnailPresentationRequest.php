<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/thumbnail-presentation.
 */
final readonly class UpdateThumbnailPresentationRequest
{
    private function __construct(
        public ?ThumbnailBackgroundColor $thumbnailBackgroundColor,
        public bool $clearThumbnailBackgroundColor,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        if (!array_key_exists('thumbnailBackgroundColor', $body)) {
            throw InvalidProductValue::for('ThumbnailBackgroundColor', 'required');
        }

        $raw = $body['thumbnailBackgroundColor'];
        if ($raw === null) {
            return new self(thumbnailBackgroundColor: null, clearThumbnailBackgroundColor: true);
        }

        if (!is_string($raw)) {
            throw InvalidProductValue::for('ThumbnailBackgroundColor', (string) $raw);
        }

        return new self(
            thumbnailBackgroundColor: ThumbnailBackgroundColor::fromString($raw),
            clearThumbnailBackgroundColor: false,
        );
    }
}
