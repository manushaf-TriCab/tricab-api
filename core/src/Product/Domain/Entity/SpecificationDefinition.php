<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\SpecificationDataType;
use TriCab\Core\Product\Domain\ValueObject\SpecificationDefinitionId;
use TriCab\Core\Product\Domain\ValueObject\SpecificationScope;

final class SpecificationDefinition
{
    private readonly string $definitionCode;
    private readonly string $definitionName;

    public function __construct(
        private readonly ?SpecificationDefinitionId $id,
        string $definitionCode,
        string $definitionName,
        private readonly SpecificationDataType $dataType,
        private readonly SpecificationScope $scope,
        private readonly DisplayOrder $displayOrder,
        private readonly bool $isRequired = false,
        private readonly bool $isFilterable = false,
        private readonly bool $isSearchable = false,
        private readonly bool $isComparable = false,
        private readonly ?string $unit = null,
        private readonly ?string $minimumValue = null,
        private readonly ?string $maximumValue = null,
        private readonly ?int $decimalPrecision = null,
        private readonly bool $isActive = true,
    ) {
        $code = trim($definitionCode);
        $name = trim($definitionName);
        if ($code === '') {
            throw InvalidProductValue::for('DefinitionCode', $definitionCode);
        }
        if ($name === '') {
            throw InvalidProductValue::for('DefinitionName', $definitionName);
        }
        if (
            $minimumValue !== null
            && $maximumValue !== null
            && (float) $minimumValue > (float) $maximumValue
        ) {
            throw InvalidProductValue::for('SpecificationRange', $minimumValue . '..' . $maximumValue);
        }
        if ($decimalPrecision !== null && ($decimalPrecision < 0 || $decimalPrecision > 6)) {
            throw InvalidProductValue::for('DecimalPrecision', (string) $decimalPrecision);
        }

        $this->definitionCode = $code;
        $this->definitionName = $name;
    }

    public function id(): ?SpecificationDefinitionId
    {
        return $this->id;
    }

    public function definitionCode(): string
    {
        return $this->definitionCode;
    }

    public function definitionName(): string
    {
        return $this->definitionName;
    }

    public function dataType(): SpecificationDataType
    {
        return $this->dataType;
    }

    public function scope(): SpecificationScope
    {
        return $this->scope;
    }

    public function displayOrder(): DisplayOrder
    {
        return $this->displayOrder;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function isFilterable(): bool
    {
        return $this->isFilterable;
    }

    public function isSearchable(): bool
    {
        return $this->isSearchable;
    }

    public function isComparable(): bool
    {
        return $this->isComparable;
    }

    public function unit(): ?string
    {
        return $this->unit;
    }

    public function minimumValue(): ?string
    {
        return $this->minimumValue;
    }

    public function maximumValue(): ?string
    {
        return $this->maximumValue;
    }

    public function decimalPrecision(): ?int
    {
        return $this->decimalPrecision;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
