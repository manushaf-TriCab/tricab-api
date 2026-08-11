<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

final readonly class AssignCatalogueNodeProductsRequest
{
    /** @param list<array{productPublicId: string, displayOrder?: int, isFeatured?: bool, isActive?: bool}> $placements */
    private function __construct(
        public array $placements,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $raw = $body['placements'] ?? null;
        if (!is_array($raw)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'placements is required.');
        }
        $placements = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'Each placement must be an object.');
            }
            $productPublicId = $item['productPublicId'] ?? null;
            if (!is_string($productPublicId) || trim($productPublicId) === '') {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'Each placement requires productPublicId.');
            }
            $placements[] = [
                'productPublicId' => $productPublicId,
                'displayOrder' => isset($item['displayOrder']) ? (int) $item['displayOrder'] : 0,
                'isFeatured' => (bool) ($item['isFeatured'] ?? false),
                'isActive' => (bool) ($item['isActive'] ?? true),
            ];
        }

        return new self($placements);
    }
}
