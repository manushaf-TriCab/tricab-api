<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\DTO;

/**
 * Server-side filters for Finance-authorized legacy bank feed queries.
 */
final class LegacyExpenseFinanceBankFilters
{
    public const MAX_LIMIT = 500;

    public function __construct(
        public readonly ?int $periodId = null,
        public readonly ?string $country = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?string $currency = null,
        public readonly ?string $matchStatus = null,
        public readonly ?string $resolution = null,
        public readonly ?string $cardholderEmail = null,
        public readonly ?string $search = null,
        public readonly ?bool $needsReceiptOnly = null,
        public readonly int $limit = 100,
        public readonly int $offset = 0,
    ) {
    }

    public function normalizedLimit(): int
    {
        return max(1, min(self::MAX_LIMIT, $this->limit));
    }

    public function normalizedOffset(): int
    {
        return max(0, $this->offset);
    }

    public function withWindow(int $limit, int $offset): self
    {
        return new self(
            periodId: $this->periodId,
            country: $this->country,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            currency: $this->currency,
            matchStatus: $this->matchStatus,
            resolution: $this->resolution,
            cardholderEmail: $this->cardholderEmail,
            search: $this->search,
            needsReceiptOnly: $this->needsReceiptOnly,
            limit: $limit,
            offset: $offset,
        );
    }
}
