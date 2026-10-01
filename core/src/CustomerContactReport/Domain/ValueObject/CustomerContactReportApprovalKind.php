<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\ValueObject;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

final readonly class CustomerContactReportApprovalKind
{
    public const CREDIT = 'credit';

    public const REWORK = 'rework';

    public const SETTLEMENT = 'settlement';

    /** @var list<string> */
    public const REQUESTABLE = [self::CREDIT, self::REWORK];

    /** @var list<string> */
    public const DECIDABLE = [self::CREDIT, self::REWORK];

    private function __construct(
        private string $value,
    ) {
    }

    public static function requestable(string $raw): self
    {
        $kind = strtolower(trim($raw));
        if ($kind === self::SETTLEMENT) {
            throw InvalidCustomerContactReportValue::for('kind', 'is raised when a credit is approved, not requested directly.');
        }
        if (!in_array($kind, self::REQUESTABLE, true)) {
            throw InvalidCustomerContactReportValue::for('kind', 'must be credit or rework.');
        }

        return new self($kind);
    }

    public static function decidable(string $raw): self
    {
        $kind = strtolower(trim($raw));
        if ($kind === self::SETTLEMENT) {
            throw InvalidCustomerContactReportValue::for('kind', 'is completed by attaching the credit note.');
        }
        if (!in_array($kind, self::DECIDABLE, true)) {
            throw InvalidCustomerContactReportValue::for('kind', 'must be credit or rework.');
        }

        return new self($kind);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function isCredit(): bool
    {
        return $this->value === self::CREDIT;
    }
}
