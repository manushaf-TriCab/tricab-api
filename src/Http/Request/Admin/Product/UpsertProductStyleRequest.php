<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class UpsertProductStyleRequest
{
    private function __construct(
        public string $styleCode,
        public string $displayName,
        public ?string $parentPublicId,
        public int $sortOrder,
        public bool $isActive,
        public ?string $expectedRowVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            styleCode: RequestFields::requireString($body, 'styleCode'),
            displayName: RequestFields::requireString($body, 'displayName'),
            parentPublicId: RequestFields::stringOrNull($body, 'parentPublicId'),
            sortOrder: RequestFields::intOrNull($body, 'sortOrder') ?? 0,
            isActive: RequestFields::boolOrDefault($body, 'isActive', true),
            expectedRowVersion: RequestFields::stringOrNull($body, 'expectedRowVersion'),
        );
    }
}
