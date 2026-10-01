<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\ValueObject;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

final class CustomerContactReportRoute
{
    public const BRANCH = 'branch';

    public const ICAR = 'icar';

    /** @var list<string> */
    public const CODES = [self::BRANCH, self::ICAR];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, self::CODES, true)) {
            throw InvalidCustomerContactReportValue::for('route', 'must be branch or icar.');
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
