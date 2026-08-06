<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

final readonly class UpsertSpecificationOptionRequest
{
    public function __construct(
        public string $definitionPublicId,
        public string $name,
        public string $code,
        public ?string $value,
        public int $displayOrder,
        public bool $isActive,
        public ?string $expectedRowVersion,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $definitionPublicIdFromRoute = null): self
    {
        $definitionPublicId = $definitionPublicIdFromRoute
            ?? (isset($data['definitionPublicId']) && is_string($data['definitionPublicId']) ? $data['definitionPublicId'] : '');
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : '';
        if (trim($definitionPublicId) === '' || trim($name) === '' || trim($code) === '') {
            throw new HttpRequestValidationFailed('VALIDATION_FAILED', 'definitionPublicId, name and code are required.', 422);
        }

        return new self(
            definitionPublicId: $definitionPublicId,
            name: $name,
            code: $code,
            value: isset($data['value']) && is_string($data['value']) ? $data['value'] : null,
            displayOrder: isset($data['displayOrder']) && is_numeric($data['displayOrder']) ? (int) $data['displayOrder'] : 0,
            isActive: array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true,
            expectedRowVersion: isset($data['expectedRowVersion']) && is_string($data['expectedRowVersion']) ? $data['expectedRowVersion'] : null,
        );
    }
}
