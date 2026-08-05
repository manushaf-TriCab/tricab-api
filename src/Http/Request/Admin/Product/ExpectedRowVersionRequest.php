<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Shared validated body for lifecycle command endpoints whose only input is
 * the optimistic-concurrency token, e.g. publish/unpublish/archive/restore/
 * soft-delete: { "expectedRowVersion": "..." }.
 */
final readonly class ExpectedRowVersionRequest
{
    private function __construct(
        public string $expectedRowVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            expectedRowVersion: RequestFields::requireString($body, 'expectedRowVersion'),
        );
    }
}
