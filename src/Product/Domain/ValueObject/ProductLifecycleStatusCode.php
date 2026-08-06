<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Seeded lifecycle status codes from Phase 1 reference data.
 */
final class ProductLifecycleStatusCode
{
    public const CONCEPT = 'concept';
    public const ACTIVE = 'active';
    public const PHASE_OUT = 'phase-out';
    public const END_OF_LIFE = 'end-of-life';
    public const REPLACED = 'replaced';

    private const SEEDED = [
        self::CONCEPT,
        self::ACTIVE,
        self::PHASE_OUT,
        self::END_OF_LIFE,
        self::REPLACED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || !in_array($normalized, self::SEEDED, true)) {
            throw InvalidProductValue::for('ProductLifecycleStatusCode', $value);
        }

        return new self($normalized);
    }

    public static function concept(): self
    {
        return new self(self::CONCEPT);
    }

    public static function active(): self
    {
        return new self(self::ACTIVE);
    }

    public static function phaseOut(): self
    {
        return new self(self::PHASE_OUT);
    }

    public static function endOfLife(): self
    {
        return new self(self::END_OF_LIFE);
    }

    public static function replaced(): self
    {
        return new self(self::REPLACED);
    }

    /**
     * @return list<string>
     */
    public static function seededCodes(): array
    {
        return self::SEEDED;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isActiveCommercial(): bool
    {
        return $this->value === self::ACTIVE;
    }
}
