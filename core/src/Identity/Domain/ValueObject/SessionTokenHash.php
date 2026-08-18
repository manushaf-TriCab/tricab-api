<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

/**
 * SHA-256 (32-byte) session token hash. Domain stores the raw binary string.
 */
final class SessionTokenHash
{
    private function __construct(private readonly string $binary)
    {
    }

    public static function fromBinary(string $binary): self
    {
        if (strlen($binary) !== 32) {
            throw InvalidIdentityValue::for('SessionTokenHash', 'binary length ' . strlen($binary));
        }

        return new self($binary);
    }

    public static function fromHex(string $hex): self
    {
        $normalized = strtolower(trim($hex));
        if (preg_match('/^[0-9a-f]{64}$/', $normalized) !== 1) {
            throw InvalidIdentityValue::for('SessionTokenHash', $hex);
        }

        $binary = hex2bin($normalized);
        if ($binary === false) {
            throw InvalidIdentityValue::for('SessionTokenHash', $hex);
        }

        return self::fromBinary($binary);
    }

    public function toBinary(): string
    {
        return $this->binary;
    }

    public function toHex(): string
    {
        return bin2hex($this->binary);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->binary, $other->binary);
    }
}
