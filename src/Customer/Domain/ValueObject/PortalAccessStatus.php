<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\ValueObject;

use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;

/**
 * Lifecycle status of a contact's customer-portal access.
 * Allowed transitions are enforced by PortalAccessPolicy, not by this value object.
 */
final class PortalAccessStatus
{
    public const NOT_INVITED = 'not-invited';
    public const INVITED = 'invited';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const DECLINED = 'declined';

    private const ALLOWED = [
        self::NOT_INVITED,
        self::INVITED,
        self::ACTIVE,
        self::SUSPENDED,
        self::DECLINED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidCustomerValue::for('PortalAccessStatus', $value);
        }

        return new self($normalized);
    }

    public static function notInvited(): self
    {
        return new self(self::NOT_INVITED);
    }

    public static function invited(): self
    {
        return new self(self::INVITED);
    }

    public static function active(): self
    {
        return new self(self::ACTIVE);
    }

    public static function suspended(): self
    {
        return new self(self::SUSPENDED);
    }

    public static function declined(): self
    {
        return new self(self::DECLINED);
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
}
