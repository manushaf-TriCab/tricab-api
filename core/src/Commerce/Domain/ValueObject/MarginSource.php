<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\ValueObject;

use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;

final class MarginSource
{
    public const TMMKTM = 'TMMKTM';
    public const STAFF_OVERRIDE = 'STAFF_OVERRIDE';
    public const TBC = 'TBC';
    public const OTHER_APPROVED_SOURCE = 'OTHER_APPROVED_SOURCE';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, [
            self::TMMKTM,
            self::STAFF_OVERRIDE,
            self::TBC,
            self::OTHER_APPROVED_SOURCE,
        ], true)) {
            throw InvalidCartValue::for('MarginSource', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
