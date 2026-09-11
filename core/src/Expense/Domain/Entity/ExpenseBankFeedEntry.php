<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Domain\ValueObject\BankFeedMatchStatus;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseFinanceResolution;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class ExpenseBankFeedEntry
{
    private function __construct(
        private ?int $id,
        private readonly ExpenseBankFeedPublicId $publicId,
        private readonly string $bankReference,
        private string $cardholderEmail,
        private ?UserId $cardholderUserId,
        private ?string $firstName,
        private ?string $lastName,
        private readonly string $country,
        private ?DateTimeImmutable $transactionDate,
        private ?DateTimeImmutable $postingDate,
        private ?DateTimeImmutable $statementDate,
        private string $merchantName,
        private ?string $merchantCity,
        private ?string $merchantCountry,
        private ?string $merchantPostcode,
        private ExpenseMoney $amount,
        private ExpenseCurrency $currency,
        private ?ExpenseMoney $foreignAmount,
        private ?ExpenseCurrency $foreignCurrency,
        private ?ExpenseMoney $netAmount,
        private ?ExpenseMoney $taxAmount,
        private ?string $transactionType,
        private ?string $merchantCategoryGroup,
        private BankFeedMatchStatus $matchStatus,
        private ?int $receiptId,
        private ?int $periodId,
        private ?ExpenseFinanceResolution $resolution,
        private ?int $offsetBankFeedEntryId,
        private ?string $resolutionNote,
        private ?UserId $resolvedByUserId,
        private ?string $legacyResolvedBy,
        private ?DateTimeImmutable $resolvedAtUtc,
        private ?string $legacyUploadedBy,
        private ?int $legacyBankFeedId,
        private readonly DateTimeImmutable $uploadedAtUtc,
        private ?RowVersionToken $rowVersion,
    ) {
    }

    public static function createImported(
        ExpenseBankFeedPublicId $publicId,
        string $bankReference,
        string $cardholderEmail,
        string $country,
        ?DateTimeImmutable $transactionDate,
        string $merchantName,
        ExpenseMoney $amount,
        ExpenseCurrency $currency,
        DateTimeImmutable $uploadedAtUtc,
        ?DateTimeImmutable $postingDate = null,
        ?DateTimeImmutable $statementDate = null,
        ?string $merchantCity = null,
        ?string $merchantCountry = null,
        ?string $merchantPostcode = null,
        ?ExpenseMoney $foreignAmount = null,
        ?ExpenseCurrency $foreignCurrency = null,
        ?ExpenseMoney $netAmount = null,
        ?ExpenseMoney $taxAmount = null,
        ?string $transactionType = null,
        ?string $merchantCategoryGroup = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?UserId $cardholderUserId = null,
        ?string $legacyUploadedBy = null,
        ?int $legacyBankFeedId = null,
    ): self {
        $ref = trim($bankReference);
        if ($ref === '') {
            throw InvalidExpenseValue::for('bankReference', $bankReference);
        }
        $email = strtolower(trim($cardholderEmail));
        if ($email === '' || !str_contains($email, '@')) {
            throw InvalidExpenseValue::for('cardholderEmail', $cardholderEmail);
        }
        $countryCode = strtoupper(trim($country));
        if (!in_array($countryCode, [Module::COUNTRY_AU, Module::COUNTRY_NZ], true)) {
            throw InvalidExpenseValue::for('country', $country);
        }

        return new self(
            id: null,
            publicId: $publicId,
            bankReference: $ref,
            cardholderEmail: $email,
            cardholderUserId: $cardholderUserId,
            firstName: self::nullableTrim($firstName),
            lastName: self::nullableTrim($lastName),
            country: $countryCode,
            transactionDate: $transactionDate,
            postingDate: $postingDate,
            statementDate: $statementDate,
            merchantName: trim($merchantName) !== '' ? trim($merchantName) : 'Unknown',
            merchantCity: self::nullableTrim($merchantCity),
            merchantCountry: self::nullableTrim($merchantCountry),
            merchantPostcode: self::nullableTrim($merchantPostcode),
            amount: $amount,
            currency: $currency,
            foreignAmount: $foreignAmount,
            foreignCurrency: $foreignCurrency,
            netAmount: $netAmount,
            taxAmount: $taxAmount,
            transactionType: self::nullableTrim($transactionType),
            merchantCategoryGroup: self::nullableTrim($merchantCategoryGroup),
            matchStatus: BankFeedMatchStatus::unmatched(),
            receiptId: null,
            periodId: null,
            resolution: null,
            offsetBankFeedEntryId: null,
            resolutionNote: null,
            resolvedByUserId: null,
            legacyResolvedBy: null,
            resolvedAtUtc: null,
            legacyUploadedBy: self::nullableTrim($legacyUploadedBy),
            legacyBankFeedId: $legacyBankFeedId,
            uploadedAtUtc: $uploadedAtUtc,
            rowVersion: null,
        );
    }

    public static function reconstitute(
        ?int $id,
        ExpenseBankFeedPublicId $publicId,
        string $bankReference,
        string $cardholderEmail,
        ?UserId $cardholderUserId,
        ?string $firstName,
        ?string $lastName,
        string $country,
        ?DateTimeImmutable $transactionDate,
        ?DateTimeImmutable $postingDate,
        ?DateTimeImmutable $statementDate,
        string $merchantName,
        ?string $merchantCity,
        ?string $merchantCountry,
        ?string $merchantPostcode,
        ExpenseMoney $amount,
        ExpenseCurrency $currency,
        ?ExpenseMoney $foreignAmount,
        ?ExpenseCurrency $foreignCurrency,
        ?ExpenseMoney $netAmount,
        ?ExpenseMoney $taxAmount,
        ?string $transactionType,
        ?string $merchantCategoryGroup,
        BankFeedMatchStatus $matchStatus,
        ?int $receiptId,
        ?int $periodId,
        ?ExpenseFinanceResolution $resolution,
        ?int $offsetBankFeedEntryId,
        ?string $resolutionNote,
        ?UserId $resolvedByUserId,
        ?string $legacyResolvedBy,
        ?DateTimeImmutable $resolvedAtUtc,
        ?string $legacyUploadedBy,
        ?int $legacyBankFeedId,
        DateTimeImmutable $uploadedAtUtc,
        ?RowVersionToken $rowVersion = null,
    ): self {
        return new self(
            $id,
            $publicId,
            $bankReference,
            strtolower(trim($cardholderEmail)),
            $cardholderUserId,
            $firstName,
            $lastName,
            strtoupper(trim($country)),
            $transactionDate,
            $postingDate,
            $statementDate,
            $merchantName,
            $merchantCity,
            $merchantCountry,
            $merchantPostcode,
            $amount,
            $currency,
            $foreignAmount,
            $foreignCurrency,
            $netAmount,
            $taxAmount,
            $transactionType,
            $merchantCategoryGroup,
            $matchStatus,
            $receiptId,
            $periodId,
            $resolution,
            $offsetBankFeedEntryId,
            $resolutionNote,
            $resolvedByUserId,
            $legacyResolvedBy,
            $resolvedAtUtc,
            $legacyUploadedBy,
            $legacyBankFeedId,
            $uploadedAtUtc,
            $rowVersion,
        );
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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

    public function matchTo(int $receiptId): void
    {
        $this->matchStatus = BankFeedMatchStatus::matched();
        $this->receiptId = $receiptId;
    }

    public function markAmbiguous(): void
    {
        $this->matchStatus = BankFeedMatchStatus::ambiguous();
        $this->receiptId = null;
    }

    public function clearMatch(): void
    {
        $this->matchStatus = BankFeedMatchStatus::unmatched();
        $this->receiptId = null;
    }

    public function assignPeriod(int $periodId): void
    {
        $this->periodId = $periodId;
    }

    public function resolve(
        ExpenseFinanceResolution $resolution,
        ?string $note,
        UserId $resolvedBy,
        DateTimeImmutable $now,
    ): void {
        if (!$resolution->isAllowedForBank() && !$resolution->isOffset()) {
            throw InvalidExpenseValue::for('resolution', $resolution->toString());
        }
        $this->resolution = $resolution;
        $this->resolutionNote = self::nullableTrim($note);
        $this->resolvedByUserId = $resolvedBy;
        $this->resolvedAtUtc = $now;
    }

    public function clearResolution(): void
    {
        $this->resolution = null;
        $this->resolutionNote = null;
        $this->resolvedByUserId = null;
        $this->legacyResolvedBy = null;
        $this->resolvedAtUtc = null;
        $this->offsetBankFeedEntryId = null;
    }

    public function offsetWith(int $otherEntryId, UserId $resolvedBy, DateTimeImmutable $now): void
    {
        $this->resolution = ExpenseFinanceResolution::offset();
        $this->offsetBankFeedEntryId = $otherEntryId;
        $this->resolvedByUserId = $resolvedBy;
        $this->resolvedAtUtc = $now;
        $this->resolutionNote = null;
    }

    public function undoOffset(): void
    {
        if ($this->resolution === null || !$this->resolution->isOffset()) {
            throw InvalidExpenseValue::for('resolution', $this->resolution?->toString() ?? '');
        }
        $this->clearResolution();
    }

    public function isPaymentType(): bool
    {
        return strcasecmp((string) $this->transactionType, Module::TYPE_PAYMENT) === 0;
    }

    public function isEligibleForOffset(): bool
    {
        return $this->matchStatus->isUnmatched()
            && $this->receiptId === null
            && $this->resolution === null
            && !$this->isPaymentType();
    }

    /**
     * Amount+currency used for auto-match: foreign when FX differs from base, else consolidated.
     *
     * @return array{0: ExpenseMoney, 1: ExpenseCurrency}
     */
    public function matchAmountAndCurrency(): array
    {
        if (
            $this->foreignAmount !== null
            && $this->foreignCurrency !== null
            && !$this->foreignCurrency->equals($this->currency)
        ) {
            return [$this->foreignAmount, $this->foreignCurrency];
        }

        return [$this->amount, $this->currency];
    }

    public function id(): ?int { return $this->id; }
    public function publicId(): ExpenseBankFeedPublicId { return $this->publicId; }
    public function bankReference(): string { return $this->bankReference; }
    public function cardholderEmail(): string { return $this->cardholderEmail; }
    public function cardholderUserId(): ?UserId { return $this->cardholderUserId; }
    public function firstName(): ?string { return $this->firstName; }
    public function lastName(): ?string { return $this->lastName; }
    public function country(): string { return $this->country; }
    public function transactionDate(): ?DateTimeImmutable { return $this->transactionDate; }
    public function postingDate(): ?DateTimeImmutable { return $this->postingDate; }
    public function statementDate(): ?DateTimeImmutable { return $this->statementDate; }
    public function merchantName(): string { return $this->merchantName; }
    public function merchantCity(): ?string { return $this->merchantCity; }
    public function merchantCountry(): ?string { return $this->merchantCountry; }
    public function merchantPostcode(): ?string { return $this->merchantPostcode; }
    public function amount(): ExpenseMoney { return $this->amount; }
    public function currency(): ExpenseCurrency { return $this->currency; }
    public function foreignAmount(): ?ExpenseMoney { return $this->foreignAmount; }
    public function foreignCurrency(): ?ExpenseCurrency { return $this->foreignCurrency; }
    public function netAmount(): ?ExpenseMoney { return $this->netAmount; }
    public function taxAmount(): ?ExpenseMoney { return $this->taxAmount; }
    public function transactionType(): ?string { return $this->transactionType; }
    public function merchantCategoryGroup(): ?string { return $this->merchantCategoryGroup; }
    public function matchStatus(): BankFeedMatchStatus { return $this->matchStatus; }
    public function receiptId(): ?int { return $this->receiptId; }
    public function periodId(): ?int { return $this->periodId; }
    public function resolution(): ?ExpenseFinanceResolution { return $this->resolution; }
    public function offsetBankFeedEntryId(): ?int { return $this->offsetBankFeedEntryId; }
    public function resolutionNote(): ?string { return $this->resolutionNote; }
    public function resolvedByUserId(): ?UserId { return $this->resolvedByUserId; }
    public function legacyResolvedBy(): ?string { return $this->legacyResolvedBy; }
    public function resolvedAtUtc(): ?DateTimeImmutable { return $this->resolvedAtUtc; }
    public function legacyUploadedBy(): ?string { return $this->legacyUploadedBy; }
    public function legacyBankFeedId(): ?int { return $this->legacyBankFeedId; }
    public function uploadedAtUtc(): DateTimeImmutable { return $this->uploadedAtUtc; }
    public function rowVersion(): ?RowVersionToken { return $this->rowVersion; }
}
