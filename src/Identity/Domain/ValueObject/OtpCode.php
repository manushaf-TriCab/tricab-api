<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

/**
 * Plaintext six-digit OTP. Never persist this value — only its hash.
 */
final class OtpCode
{
    private const PATTERN = '/^\d{6}$/';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw InvalidIdentityValue::for('OtpCode', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
