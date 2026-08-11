<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

final readonly class MoveCatalogueNodeRequest
{
    private function __construct(
        public ?string $newParentPublicId,
        public string $expectedRowVersion,
        public ?int $displayOrder,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            newParentPublicId: RequestFields::stringOrNull($body, 'newParentPublicId'),
            expectedRowVersion: RequestFields::requireString($body, 'expectedRowVersion'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder'),
        );
    }
}
