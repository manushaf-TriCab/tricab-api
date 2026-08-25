<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

final class ExternalProvider
{
    public const MICROSOFT_ENTRA = 'microsoft_entra';
    public const EMAIL_OTP = 'email_otp';
    public const GOOGLE = 'google';
    public const APPLE = 'apple';
    public const PASSKEY = 'passkey';

    private const ALLOWED = [
        self::MICROSOFT_ENTRA,
        self::EMAIL_OTP,
        self::GOOGLE,
        self::APPLE,
        self::PASSKEY,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('-', '_', $normalized);

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidIdentityValue::for('ExternalProvider', $value);
        }

        return new self($normalized);
    }

    public static function microsoftEntra(): self
    {
        return new self(self::MICROSOFT_ENTRA);
    }

    public static function emailOtp(): self
    {
        return new self(self::EMAIL_OTP);
    }

    public static function google(): self
    {
        return new self(self::GOOGLE);
    }

    public static function apple(): self
    {
        return new self(self::APPLE);
    }

    public static function passkey(): self
    {
        return new self(self::PASSKEY);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isMicrosoftEntra(): bool
    {
        return $this->value === self::MICROSOFT_ENTRA;
    }

    public function isEmailOtp(): bool
    {
        return $this->value === self::EMAIL_OTP;
    }

    public function isGoogle(): bool
    {
        return $this->value === self::GOOGLE;
    }

    public function isApple(): bool
    {
        return $this->value === self::APPLE;
    }

    public function isPasskey(): bool
    {
        return $this->value === self::PASSKEY;
    }
}
