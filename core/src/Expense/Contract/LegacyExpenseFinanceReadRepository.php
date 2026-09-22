<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceFilters;

/**
 * Finance-authorized READ-ONLY view of legacy TriExpense receipts.
 *
 * MUST only be consumed by services that call requireFinance (or equivalent).
 * Never inject this into owner-scoped My Expenses / normal attachment paths.
 *
 * SQL remains SELECT-only via LegacyReadOnlySqlGuard.
 */
interface LegacyExpenseFinanceReadRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $legacyReceiptId): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listFinance(LegacyExpenseFinanceFilters $filters): array;

    public function countFinance(LegacyExpenseFinanceFilters $filters): int;

    /**
     * @return array<string, int> status => count
     */
    public function countByStatus(?string $dateFrom, ?string $dateTo): array;

    /**
     * Currency buckets for monetary totals (never cross-currency summed).
     *
     * @return list<array{currency: string, totalAmount: string, receiptCount: int}>
     */
    public function sumByCurrency(LegacyExpenseFinanceFilters $filters): array;

    /**
     * Historical bank-feed census (read-only comparison with Azure).
     *
     * @return list<array{periodId: int|null, country: string|null, matchStatus: string|null, resolution: string|null, entryCount: int}>
     */
    public function countBankFeedByPeriodCountryStatus(): array;

    /**
     * @return list<array{periodId: int, country: string|null, status: string|null, periodCount: int}>
     */
    public function countPeriodsByCountryStatus(): array;
}
