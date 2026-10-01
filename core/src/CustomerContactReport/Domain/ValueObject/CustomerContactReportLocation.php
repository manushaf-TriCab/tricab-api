<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\ValueObject;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

final class CustomerContactReportLocation
{
    /** @var list<string> */
    public const CODES = ['A', 'B', 'C', 'H', 'M', 'P', 'S'];

    private function __construct(private readonly string $code)
    {
    }

    public static function fromString(string $value): self
    {
        $code = strtoupper(trim($value));
        if (!in_array($code, self::CODES, true)) {
            throw InvalidCustomerContactReportValue::for('location', 'must be one of A, B, C, H, M, P, S.');
        }

        return new self($code);
    }

    public function toString(): string
    {
        return $this->code;
    }
}
