<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceBankFilters;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;

/** Used when legacy finance read is disabled or TC MySQL is unavailable. */
final class NullLegacyExpenseFinanceBankReadRepository implements LegacyExpenseFinanceBankReadRepository
{
    public function findBankById(int $legacyBankFeedId): ?array
    {
        return null;
    }

    public function listBank(LegacyExpenseFinanceBankFilters $filters): array
    {
        return [];
    }

    public function countBank(LegacyExpenseFinanceBankFilters $filters): int
    {
        return 0;
    }

    public function sumBankByCurrency(LegacyExpenseFinanceBankFilters $filters): array
    {
        return [];
    }

    public function findPeriodById(int $legacyPeriodId): ?array
    {
        return null;
    }

    public function listPeriods(?string $country): array
    {
        return [];
    }

    public function countBankByPeriodCountryMatchResolution(): array
    {
        return [];
    }
}
