<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

use TriCab\Core\Product\Application\DTO\SpecificationValidationRules;

final readonly class UpsertSpecificationDefinitionCommand
{
    public function __construct(
        public string $name,
        public string $code,
        public string $dataTypeCode,
        public string $scopeCode = 'product',
        public ?string $groupPublicId = null,
        public ?string $description = null,
        public ?string $unit = null,
        public ?string $unitPublicId = null,
        public int $displayOrder = 0,
        public bool $isRequired = false,
        public bool $isFilterable = false,
        public bool $isSearchable = false,
        public bool $isComparable = false,
        public ?string $minimumValue = null,
        public ?string $maximumValue = null,
        public ?int $decimalPrecision = null,
        public ?SpecificationValidationRules $validationRules = null,
        public bool $isActive = true,
        public ?string $expectedRowVersion = null,
        public ?string $definitionPublicId = null,
    ) {
    }
}
