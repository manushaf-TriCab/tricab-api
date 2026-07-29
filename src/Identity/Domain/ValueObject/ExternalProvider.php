<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

final class ExternalProvider
{
    public const MICROSOFT_ENTRA = 'microsoft_entra';

    private const ALLOWED = [
        self::MICROSOFT_ENTRA,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidIdentityValue::for('ExternalProvider', $value);
        }

        return new self($normalized);
    }

    public static function microsoftEntra(): self
    {
        return new self(self::MICROSOFT_ENTRA);
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
}
