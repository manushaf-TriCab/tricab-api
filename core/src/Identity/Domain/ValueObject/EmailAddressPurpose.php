<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

/**
 * What an address is for, which decides which providers may resolve through it.
 *
 * `corporate` is a mailbox TriCab issues and controls. `personal` is one the
 * person controls: a pre-start candidate's address, and the permanent address
 * of an employee who is never issued a mailbox.
 */
final class EmailAddressPurpose
{
    public const PERSONAL = 'personal';
    public const CORPORATE = 'corporate';

    private const ALLOWED = [self::PERSONAL, self::CORPORATE];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidIdentityValue::for('EmailAddressPurpose', $value);
        }

        return new self($normalized);
    }

    public static function personal(): self
    {
        return new self(self::PERSONAL);
    }

    public static function corporate(): self
    {
        return new self(self::CORPORATE);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isPersonal(): bool
    {
        return $this->value === self::PERSONAL;
    }

    public function isCorporate(): bool
    {
        return $this->value === self::CORPORATE;
    }
}
