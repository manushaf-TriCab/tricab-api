<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Per-resource inheritance mode for a child style.
 *
 * INHERIT — use ancestor data only; no local records are consulted.
 * INHERIT_AND_EXTEND — ancestor data plus local additions/overrides.
 * LOCAL — ignore ancestors for this resource type.
 */
final class StyleInheritanceMode
{
    public const INHERIT = 'INHERIT';
    public const INHERIT_AND_EXTEND = 'INHERIT_AND_EXTEND';
    public const LOCAL = 'LOCAL';

    private const ALLOWED = [
        self::INHERIT,
        self::INHERIT_AND_EXTEND,
        self::LOCAL,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('StyleInheritanceMode', $value);
        }

        return new self($normalized);
    }

    public static function inherit(): self
    {
        return new self(self::INHERIT);
    }

    public static function inheritAndExtend(): self
    {
        return new self(self::INHERIT_AND_EXTEND);
    }

    public static function local(): self
    {
        return new self(self::LOCAL);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function usesAncestorData(): bool
    {
        return $this->value === self::INHERIT || $this->value === self::INHERIT_AND_EXTEND;
    }

    public function usesLocalData(): bool
    {
        return $this->value === self::LOCAL || $this->value === self::INHERIT_AND_EXTEND;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
