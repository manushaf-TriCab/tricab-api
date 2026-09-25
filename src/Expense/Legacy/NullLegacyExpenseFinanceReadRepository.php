<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceFilters;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository;

/** Used when legacy finance read is disabled or TC MySQL is unavailable. */
final class NullLegacyExpenseFinanceReadRepository implements LegacyExpenseFinanceReadRepository
{
    public function findById(int $legacyReceiptId): ?array
    {
        return null;
    }

    public function listFinance(LegacyExpenseFinanceFilters $filters): array
    {
        return [];
    }

    public function countFinance(LegacyExpenseFinanceFilters $filters): int
    {
        return 0;
    }

    public function countByStatus(?string $dateFrom, ?string $dateTo): array
    {
        return [];
    }

    public function sumByCurrency(LegacyExpenseFinanceFilters $filters): array
    {
        return [];
    }

    public function countBankFeedByPeriodCountryStatus(): array
    {
        return [];
    }

    public function countPeriodsByCountryStatus(): array
    {
        return [];
    }
}
