<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

/**
 * Opaque 32-byte WebAuthn user handle. Never an email address.
 */
final class WebAuthnUserHandle
{
    private function __construct(private readonly string $bytes)
    {
    }

    public static function generate(): self
    {
        return new self(random_bytes(32));
    }

    public static function fromBinary(string $bytes): self
    {
        if (strlen($bytes) !== 32) {
            throw InvalidIdentityValue::for('WebAuthnUserHandle', 'length');
        }

        return new self($bytes);
    }

    public static function fromHex(string $hex): self
    {
        $normalized = strtolower(trim($hex));
        $bytes = hex2bin($normalized);
        if ($bytes === false) {
            throw InvalidIdentityValue::for('WebAuthnUserHandle', $hex);
        }

        return self::fromBinary($bytes);
    }

    public function toBinary(): string
    {
        return $this->bytes;
    }

    public function toHex(): string
    {
        return bin2hex($this->bytes);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }
}
