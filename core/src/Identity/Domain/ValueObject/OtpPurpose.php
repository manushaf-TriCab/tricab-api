<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

final class OtpPurpose
{
    public const GUEST_LOGIN = 'guest_login';

    private const ALLOWED = [
        self::GUEST_LOGIN,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('-', '_', $normalized);

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidIdentityValue::for('OtpPurpose', $value);
        }

        return new self($normalized);
    }

    public static function guestLogin(): self
    {
        return new self(self::GUEST_LOGIN);
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
