<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

final readonly class UpsertSpecificationUnitRequest
{
    public function __construct(
        public string $name,
        public string $code,
        public string $symbol,
        public ?string $description,
        public int $displayOrder,
        public bool $isActive,
        public ?string $expectedRowVersion,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : '';
        $symbol = isset($data['symbol']) && is_string($data['symbol']) ? $data['symbol'] : '';
        if (trim($name) === '' || trim($code) === '' || trim($symbol) === '') {
            throw new HttpRequestValidationFailed('VALIDATION_FAILED', 'name, code and symbol are required.', 422);
        }

        return new self(
            name: $name,
            code: $code,
            symbol: $symbol,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            displayOrder: isset($data['displayOrder']) && is_numeric($data['displayOrder']) ? (int) $data['displayOrder'] : 0,
            isActive: array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true,
            expectedRowVersion: isset($data['expectedRowVersion']) && is_string($data['expectedRowVersion']) ? $data['expectedRowVersion'] : null,
        );
    }
}
