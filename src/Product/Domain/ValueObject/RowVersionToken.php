<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Optimistic concurrency token for SQL Server rowversion.
 *
 * Encoding for API transfer: Base64 of the raw 8-byte rowversion binary.
 * Persistence adapters convert between binary rowversion and this token.
 */
final class RowVersionToken
{
    private function __construct(private readonly string $base64)
    {
    }

    public static function fromBase64(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidProductValue::for('RowVersionToken', $value);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false || strlen($decoded) !== 8) {
            throw InvalidProductValue::for('RowVersionToken', $value);
        }

        return new self($normalized);
    }

    public static function fromBinary(string $binary): self
    {
        if (strlen($binary) !== 8) {
            throw InvalidProductValue::for('RowVersionToken', 'binary-length-' . strlen($binary));
        }

        return new self(base64_encode($binary));
    }

    public function toBase64(): string
    {
        return $this->base64;
    }

    public function toBinary(): string
    {
        $decoded = base64_decode($this->base64, true);
        if ($decoded === false) {
            throw InvalidProductValue::for('RowVersionToken', $this->base64);
        }

        return $decoded;
    }

    public function equals(self $other): bool
    {
        return $this->base64 === $other->base64;
    }
}
