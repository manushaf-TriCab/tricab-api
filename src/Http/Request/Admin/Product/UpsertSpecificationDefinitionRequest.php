<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Core\Product\Application\DTO\SpecificationValidationRules;

final readonly class UpsertSpecificationDefinitionRequest
{
    public function __construct(
        public string $name,
        public string $code,
        public string $dataTypeCode,
        public string $scopeCode,
        public ?string $groupPublicId,
        public ?string $description,
        public ?string $unit,
        public ?string $unitPublicId,
        public int $displayOrder,
        public bool $isRequired,
        public bool $isFilterable,
        public bool $isSearchable,
        public bool $isComparable,
        public ?string $minimumValue,
        public ?string $maximumValue,
        public ?int $decimalPrecision,
        public SpecificationValidationRules $validationRules,
        public bool $isActive,
        public ?string $expectedRowVersion,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : '';
        $dataType = isset($data['dataTypeCode']) && is_string($data['dataTypeCode']) ? $data['dataTypeCode'] : '';
        if (trim($name) === '' || trim($code) === '' || trim($dataType) === '') {
            throw new HttpRequestValidationFailed('VALIDATION_FAILED', 'name, code and dataTypeCode are required.', 422);
        }
        $rules = isset($data['validationRules']) && is_array($data['validationRules'])
            ? SpecificationValidationRules::fromArray($data['validationRules'])
            : new SpecificationValidationRules();

        return new self(
            name: $name,
            code: $code,
            dataTypeCode: $dataType,
            scopeCode: isset($data['scopeCode']) && is_string($data['scopeCode']) ? $data['scopeCode'] : 'product',
            groupPublicId: isset($data['groupPublicId']) && is_string($data['groupPublicId']) ? $data['groupPublicId'] : null,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            unit: isset($data['unit']) && is_string($data['unit']) ? $data['unit'] : null,
            unitPublicId: isset($data['unitPublicId']) && is_string($data['unitPublicId']) ? $data['unitPublicId'] : null,
            displayOrder: isset($data['displayOrder']) && is_numeric($data['displayOrder']) ? (int) $data['displayOrder'] : 0,
            isRequired: (bool) ($data['isRequired'] ?? false),
            isFilterable: (bool) ($data['isFilterable'] ?? false),
            isSearchable: (bool) ($data['isSearchable'] ?? false),
            isComparable: (bool) ($data['isComparable'] ?? false),
            minimumValue: isset($data['minimumValue']) && (is_string($data['minimumValue']) || is_numeric($data['minimumValue'])) ? (string) $data['minimumValue'] : null,
            maximumValue: isset($data['maximumValue']) && (is_string($data['maximumValue']) || is_numeric($data['maximumValue'])) ? (string) $data['maximumValue'] : null,
            decimalPrecision: isset($data['decimalPrecision']) && is_numeric($data['decimalPrecision']) ? (int) $data['decimalPrecision'] : null,
            validationRules: $rules,
            isActive: array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true,
            expectedRowVersion: isset($data['expectedRowVersion']) && is_string($data['expectedRowVersion']) ? $data['expectedRowVersion'] : null,
        );
    }
}
