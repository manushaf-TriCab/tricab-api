<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\RequestFields;

final readonly class UpsertPriceListRequest
{
    public function __construct(
        public string $name,
        public string $code,
        public string $currencyPublicId,
        public ?string $regionPublicId,
        public ?string $marketPublicId,
        public ?string $legacySourceCode,
        public ?string $legacyContextType,
        public ?string $legacyContextCode,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
        public bool $isActive,
        public ?string $expectedRowVersion,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            name: RequestFields::requireString($body, 'name'),
            code: RequestFields::requireString($body, 'code'),
            currencyPublicId: RequestFields::requireString($body, 'currencyPublicId'),
            regionPublicId: RequestFields::stringOrNull($body, 'regionPublicId'),
            marketPublicId: RequestFields::stringOrNull($body, 'marketPublicId'),
            legacySourceCode: RequestFields::stringOrNull($body, 'legacySourceCode'),
            legacyContextType: RequestFields::stringOrNull($body, 'legacyContextType'),
            legacyContextCode: RequestFields::stringOrNull($body, 'legacyContextCode'),
            effectiveFromUtc: RequestFields::stringOrNull($body, 'effectiveFromUtc'),
            effectiveToUtc: RequestFields::stringOrNull($body, 'effectiveToUtc'),
            isActive: RequestFields::boolOrDefault($body, 'isActive', true),
            expectedRowVersion: RequestFields::stringOrNull($body, 'expectedRowVersion'),
        );
    }
}
