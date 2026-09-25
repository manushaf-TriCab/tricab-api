<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceBankFilters;

/**
 * Finance-authorized READ-ONLY view of legacy TriExpense bank feed rows and periods.
 *
 * MUST only be consumed by services that call requireFinance (or equivalent).
 * SQL remains SELECT-only via LegacyReadOnlySqlGuard.
 */
interface LegacyExpenseFinanceBankReadRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findBankById(int $legacyBankFeedId): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listBank(LegacyExpenseFinanceBankFilters $filters): array;

    public function countBank(LegacyExpenseFinanceBankFilters $filters): int;

    /**
     * Currency buckets for monetary totals (never cross-currency summed).
     *
     * @return list<array{currency: string, totalAmount: string, entryCount: int}>
     */
    public function sumBankByCurrency(LegacyExpenseFinanceBankFilters $filters): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findPeriodById(int $legacyPeriodId): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listPeriods(?string $country): array;

    /**
     * Historical census used by the Finance workspace.
     *
     * @return list<array{periodId: int|null, country: string|null, matchStatus: string|null, resolution: string|null, entryCount: int}>
     */
    public function countBankByPeriodCountryMatchResolution(): array;
}
