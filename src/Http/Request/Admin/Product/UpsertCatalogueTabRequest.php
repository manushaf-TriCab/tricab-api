<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class UpsertCatalogueTabRequest
{
    private function __construct(
        public string $tabName,
        public string $tabCode,
        public string $slug,
        public ?string $description,
        public int $displayOrder,
        public bool $isActive,
        public bool $isPublic,
        public ?string $expectedRowVersion,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            tabName: RequestFields::requireString($body, 'tabName'),
            tabCode: RequestFields::requireString($body, 'tabCode'),
            slug: RequestFields::requireString($body, 'slug'),
            description: RequestFields::stringOrNull($body, 'description'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            isActive: RequestFields::boolOrDefault($body, 'isActive', true),
            isPublic: RequestFields::boolOrDefault($body, 'isPublic', true),
            expectedRowVersion: RequestFields::stringOrNull($body, 'expectedRowVersion'),
        );
    }
}
