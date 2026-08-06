<?php
declare(strict_types=1);
namespace TriCab\Api\Http\Request\Admin\Product;
use TriCab\Api\Http\Request\Admin\RequestFields;
final readonly class UpsertProductSeriesRequest {
    private function __construct(
        public string $categoryPublicId,
        public string $name,
        public string $code,
        public ?string $description,
        public int $displayOrder,
        public bool $isActive,
        public ?string $expectedRowVersion,
    ) {}
    /** @param array<string,mixed> $body */
    public static function fromArray(array $body): self {
        return new self(
            categoryPublicId: RequestFields::requireString($body, 'categoryPublicId'),
            name: RequestFields::requireString($body, 'name'),
            code: RequestFields::requireString($body, 'code'),
            description: RequestFields::stringOrNull($body, 'description'),
            displayOrder: RequestFields::intOrNull($body, 'displayOrder') ?? 0,
            isActive: RequestFields::boolOrDefault($body, 'isActive', true),
            expectedRowVersion: RequestFields::stringOrNull($body, 'expectedRowVersion'),
        );
    }
}
