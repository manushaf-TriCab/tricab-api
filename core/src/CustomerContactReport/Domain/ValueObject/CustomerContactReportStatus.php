<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\ValueObject;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Module;

final class CustomerContactReportStatus
{
    public const SUBMITTED = 'submitted';

    public const ASSIGNED = 'assigned';

    public const COMPLETED = 'completed';

    public const CLOSED = 'closed';

    /** Unfinished work first. Not alphabetical. */
    private const RANK = [
        self::SUBMITTED => 0,
        self::ASSIGNED => 1,
        self::COMPLETED => 2,
        self::CLOSED => 3,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function submitted(): self
    {
        return new self(self::SUBMITTED);
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (!isset(self::RANK[$normalized])) {
            throw InvalidCustomerContactReportValue::for('status', 'is not a Customer Contact status.');
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function rank(): int
    {
        return self::RANK[$this->value];
    }

    public function label(): string
    {
        return Module::STATUS_LABELS[$this->value];
    }
}
