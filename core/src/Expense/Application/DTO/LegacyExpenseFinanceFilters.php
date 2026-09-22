<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\DTO;

/**
 * Server-side filters for Finance-authorized legacy receipt queries.
 */
final class LegacyExpenseFinanceFilters
{
    /**
     * @param list<string>|null $ownerKeys when set, restrict to those created_by keys (employee drill-down)
     */
    public function __construct(
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?string $status = null,
        public readonly ?string $search = null,
        public readonly ?string $currency = null,
        public readonly ?string $customer = null,
        public readonly ?string $resolution = null,
        public readonly ?bool $unmatchedOnly = null,
        public readonly ?bool $excludeDraft = true,
        public readonly ?array $ownerKeys = null,
        public readonly int $limit = 500,
        public readonly int $offset = 0,
    ) {
    }

    /**
     * @param list<string> $ownerKeys
     */
    public function withOwnerKeys(array $ownerKeys): self
    {
        return new self(
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            status: $this->status,
            search: $this->search,
            currency: $this->currency,
            customer: $this->customer,
            resolution: $this->resolution,
            unmatchedOnly: $this->unmatchedOnly,
            excludeDraft: $this->excludeDraft,
            ownerKeys: $ownerKeys,
            limit: $this->limit,
            offset: $this->offset,
        );
    }
}
