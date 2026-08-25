<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

final class PasskeyChallengePurpose
{
    public const REGISTRATION = 'registration';
    public const AUTHENTICATION = 'authentication';

    private function __construct(private readonly string $value)
    {
    }

    public static function registration(): self
    {
        return new self(self::REGISTRATION);
    }

    public static function authentication(): self
    {
        return new self(self::AUTHENTICATION);
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, [self::REGISTRATION, self::AUTHENTICATION], true)) {
            throw InvalidIdentityValue::for('PasskeyChallengePurpose', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function isRegistration(): bool
    {
        return $this->value === self::REGISTRATION;
    }
}
