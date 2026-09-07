<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodStatus;

/**
 * Finance period concept. Close-rule enforcement is a business decision — not Stage 2.
 */
final class ExpensePeriod
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $country,
        private readonly DateTimeImmutable $periodStart,
        private readonly DateTimeImmutable $periodEnd,
        private readonly ExpensePeriodStatus $status,
        private readonly ?DateTimeImmutable $closedAt,
    ) {
    }

    public function id(): ?int { return $this->id; }
    public function country(): string { return $this->country; }
    public function periodStart(): DateTimeImmutable { return $this->periodStart; }
    public function periodEnd(): DateTimeImmutable { return $this->periodEnd; }
    public function status(): ExpensePeriodStatus { return $this->status; }
    public function closedAt(): ?DateTimeImmutable { return $this->closedAt; }
}