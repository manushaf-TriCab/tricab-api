<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\ValueObject;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

final class CustomerContactReportErrorCode
{
    /** @var list<string> */
    public const CODES = ['SLS', 'CUS', 'MFG', 'MWH', 'SWH', 'PMW', 'MSC'];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, self::CODES, true)) {
            throw InvalidCustomerContactReportValue::for('errorCode', 'is not a Customer Contact error code.');
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
