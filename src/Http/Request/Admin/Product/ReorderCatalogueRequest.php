<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class ReorderCatalogueRequest
{
    /** @param list<string> $orderedPublicIds */
    private function __construct(
        public array $orderedPublicIds,
        public ?string $parentPublicId,
        public ?string $tabPublicId,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $ids = $body['orderedPublicIds'] ?? null;
        if (!is_array($ids) || $ids === []) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'orderedPublicIds is required.');
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'orderedPublicIds must be strings.');
            }
            $ordered[] = $id;
        }

        return new self(
            orderedPublicIds: $ordered,
            parentPublicId: RequestFields::stringOrNull($body, 'parentPublicId'),
            tabPublicId: RequestFields::stringOrNull($body, 'tabPublicId'),
        );
    }
}
