<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

final class UserStatus
{
    public const ACTIVE = 'active';
    public const PENDING = 'pending';
    public const DISABLED = 'disabled';

    private const ALLOWED = [
        self::ACTIVE,
        self::PENDING,
        self::DISABLED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidIdentityValue::for('UserStatus', $value);
        }

        return new self($normalized);
    }

    public static function active(): self
    {
        return new self(self::ACTIVE);
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isActive(): bool
    {
        return $this->value === self::ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->value === self::PENDING;
    }

    public function isDisabled(): bool
    {
        return $this->value === self::DISABLED;
    }

    public function allowsAuthentication(): bool
    {
        return $this->isActive();
    }
}
