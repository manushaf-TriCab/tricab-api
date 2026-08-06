<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Matches Phase 1 SpecificationDefinitions.DataTypeCode check constraint.
 */
final class SpecificationDataType
{
    public const TEXT = 'text';
    public const LONG_TEXT = 'long-text';
    public const INTEGER = 'integer';
    public const DECIMAL = 'decimal';
    public const BOOLEAN = 'boolean';
    public const DATE = 'date';
    public const SINGLE_SELECT = 'single-select';
    public const MULTI_SELECT = 'multi-select';
    public const MEASUREMENT = 'measurement';
    public const RANGE = 'range';
    public const URL = 'url';

    private const ALLOWED = [
        self::TEXT,
        self::LONG_TEXT,
        self::INTEGER,
        self::DECIMAL,
        self::BOOLEAN,
        self::DATE,
        self::SINGLE_SELECT,
        self::MULTI_SELECT,
        self::MEASUREMENT,
        self::RANGE,
        self::URL,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('SpecificationDataType', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isSelect(): bool
    {
        return $this->value === self::SINGLE_SELECT || $this->value === self::MULTI_SELECT;
    }
}
