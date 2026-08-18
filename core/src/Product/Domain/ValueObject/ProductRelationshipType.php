<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class ProductRelationshipType
{
    public const ACCESSORY = 'accessory';
    public const RELATED = 'related';
    public const REPLACEMENT = 'replacement';
    public const CROSS_SELL = 'cross-sell';
    public const UP_SELL = 'up-sell';

    private const ALLOWED = [
        self::ACCESSORY,
        self::RELATED,
        self::REPLACEMENT,
        self::CROSS_SELL,
        self::UP_SELL,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('ProductRelationshipType', $value);
        }

        return new self($normalized);
    }

    public static function accessory(): self
    {
        return new self(self::ACCESSORY);
    }

    public static function related(): self
    {
        return new self(self::RELATED);
    }

    public static function replacement(): self
    {
        return new self(self::REPLACEMENT);
    }

    public static function crossSell(): self
    {
        return new self(self::CROSS_SELL);
    }

    public static function upSell(): self
    {
        return new self(self::UP_SELL);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
