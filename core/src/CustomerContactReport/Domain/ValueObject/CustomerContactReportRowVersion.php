<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\ValueObject;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

/**
 * Opaque optimistic-concurrency token. The API carries the base64 form of
 * the 8-byte SQL Server rowversion. Callers never see the raw timestamp.
 */
final class CustomerContactReportRowVersion
{
    private function __construct(private readonly string $binary)
    {
    }

    public static function fromBase64(string $value): self
    {
        $normalized = trim($value);
        $decoded = base64_decode($normalized, true);
        if ($normalized === '' || $decoded === false || strlen($decoded) !== 8) {
            throw InvalidCustomerContactReportValue::for('rowVersion', 'is malformed.');
        }

        return new self($decoded);
    }

    public static function fromBinary(string $binary): self
    {
        if (strlen($binary) !== 8) {
            throw InvalidCustomerContactReportValue::for('rowVersion', 'is malformed.');
        }

        return new self($binary);
    }

    public static function fromCounter(int $counter): self
    {
        if ($counter < 0) {
            throw InvalidCustomerContactReportValue::for('rowVersion', 'is malformed.');
        }

        return new self(pack('J', $counter));
    }

    public function toBase64(): string
    {
        return base64_encode($this->binary);
    }

    public function toBinary(): string
    {
        return $this->binary;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->binary, $other->binary);
    }
}
