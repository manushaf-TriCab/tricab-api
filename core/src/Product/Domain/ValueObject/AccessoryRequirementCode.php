<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class AccessoryRequirementCode
{
    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';
    public const COMPATIBLE = 'compatible';

    private const ALLOWED = [self::REQUIRED, self::OPTIONAL, self::COMPATIBLE];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('AccessoryRequirementCode', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
