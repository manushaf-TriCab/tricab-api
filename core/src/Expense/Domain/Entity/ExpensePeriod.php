<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseStatusTransition;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodStatus;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class ExpensePeriod
{
    private function __construct(
        private ?int $id,
        private readonly ExpensePeriodPublicId $publicId,
        private readonly string $country,
        private DateTimeImmutable $periodStart,
        private DateTimeImmutable $periodEnd,
        private ?DateTimeImmutable $transactionStartDate,
        private ?DateTimeImmutable $transactionEndDate,
        private ExpensePeriodStatus $status,
        private ?DateTimeImmutable $closedAt,
        private ?UserId $closedByUserId,
        private readonly DateTimeImmutable $createdAtUtc,
        private ?int $legacyPeriodId,
        private ?RowVersionToken $rowVersion,
    ) {
    }

    public static function open(
        ExpensePeriodPublicId $publicId,
        string $country,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        ?DateTimeImmutable $transactionStartDate,
        ?DateTimeImmutable $transactionEndDate,
        DateTimeImmutable $createdAtUtc,
        ?int $legacyPeriodId = null,
    ): self {
        return new self(
            id: null,
            publicId: $publicId,
            country: strtoupper(trim($country)),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            transactionStartDate: $transactionStartDate,
            transactionEndDate: $transactionEndDate,
            status: ExpensePeriodStatus::open(),
            closedAt: null,
            closedByUserId: null,
            createdAtUtc: $createdAtUtc,
            legacyPeriodId: $legacyPeriodId,
            rowVersion: null,
        );
    }

    public static function reconstitute(
        ?int $id,
        ExpensePeriodPublicId $publicId,
        string $country,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        ?DateTimeImmutable $transactionStartDate,
        ?DateTimeImmutable $transactionEndDate,
        ExpensePeriodStatus $status,
        ?DateTimeImmutable $closedAt,
        ?UserId $closedByUserId,
        DateTimeImmutable $createdAtUtc,
        ?int $legacyPeriodId = null,
        ?RowVersionToken $rowVersion = null,
    ): self {
        return new self(
            $id,
            $publicId,
            strtoupper(trim($country)),
            $periodStart,
            $periodEnd,
            $transactionStartDate,
            $transactionEndDate,
            $status,
            $closedAt,
            $closedByUserId,
            $createdAtUtc,
            $legacyPeriodId,
            $rowVersion,
        );
    }

    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withRowVersion(RowVersionToken $rowVersion): self
    {
        $clone = clone $this;
        $clone->rowVersion = $rowVersion;

        return $clone;
    }

    public function updateWindow(
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        ?DateTimeImmutable $transactionStartDate,
        ?DateTimeImmutable $transactionEndDate,
    ): void {
        $this->assertOpen();
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
        $this->transactionStartDate = $transactionStartDate;
        $this->transactionEndDate = $transactionEndDate;
    }

    public function close(UserId $closedBy, DateTimeImmutable $now): void
    {
        $this->assertOpen();
        $this->status = ExpensePeriodStatus::closed();
        $this->closedAt = $now;
        $this->closedByUserId = $closedBy;
    }

    public function assertOpen(): void
    {
        if (!$this->status->isOpen()) {
            throw InvalidExpenseStatusTransition::periodAlreadyClosed();
        }
    }

    public function id(): ?int { return $this->id; }
    public function publicId(): ExpensePeriodPublicId { return $this->publicId; }
    public function country(): string { return $this->country; }
    public function periodStart(): DateTimeImmutable { return $this->periodStart; }
    public function periodEnd(): DateTimeImmutable { return $this->periodEnd; }
    public function transactionStartDate(): ?DateTimeImmutable { return $this->transactionStartDate; }
    public function transactionEndDate(): ?DateTimeImmutable { return $this->transactionEndDate; }
    public function status(): ExpensePeriodStatus { return $this->status; }
    public function closedAt(): ?DateTimeImmutable { return $this->closedAt; }
    public function closedByUserId(): ?UserId { return $this->closedByUserId; }
    public function createdAtUtc(): DateTimeImmutable { return $this->createdAtUtc; }
    public function legacyPeriodId(): ?int { return $this->legacyPeriodId; }
    public function rowVersion(): ?RowVersionToken { return $this->rowVersion; }
}
