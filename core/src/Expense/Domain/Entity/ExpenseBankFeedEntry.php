<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Expense\Domain\ValueObject\BankFeedMatchStatus;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;

/**
 * Early bank-feed concept so Receipt linking does not require redesign later.
 * Full finance API is out of Stage 2 scope.
 */
final class ExpenseBankFeedEntry
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $bankReference,
        private readonly string $cardholderEmail,
        private readonly string $country,
        private readonly ?DateTimeImmutable $transactionDate,
        private readonly string $merchantName,
        private readonly ExpenseMoney $amount,
        private readonly ExpenseCurrency $currency,
        private readonly BankFeedMatchStatus $matchStatus,
        private readonly ?int $receiptId,
        private readonly ?int $periodId,
        private readonly ?string $resolution,
    ) {
    }

    public function id(): ?int { return $this->id; }
    public function bankReference(): string { return $this->bankReference; }
    public function cardholderEmail(): string { return $this->cardholderEmail; }
    public function country(): string { return $this->country; }
    public function transactionDate(): ?DateTimeImmutable { return $this->transactionDate; }
    public function merchantName(): string { return $this->merchantName; }
    public function amount(): ExpenseMoney { return $this->amount; }
    public function currency(): ExpenseCurrency { return $this->currency; }
    public function matchStatus(): BankFeedMatchStatus { return $this->matchStatus; }
    public function receiptId(): ?int { return $this->receiptId; }
    public function periodId(): ?int { return $this->periodId; }
    public function resolution(): ?string { return $this->resolution; }
}