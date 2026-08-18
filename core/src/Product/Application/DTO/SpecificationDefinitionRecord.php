<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class SpecificationDefinitionRecord
{
    /**
     * @param list<SpecificationOptionRecord> $options
     */
    public function __construct(
        public string $publicId,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $groupPublicId,
        public ?string $groupCode,
        public ?string $groupName,
        public string $dataTypeCode,
        public string $scopeCode,
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
        public bool $isDeleted,
        public string $rowVersion,
        public array $options = [],
        public ?string $createdAtUtc = null,
        public ?string $updatedAtUtc = null,
        public int $productValueCount = 0,
        public int $variantValueCount = 0,
    ) {
    }
}
