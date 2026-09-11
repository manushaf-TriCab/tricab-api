<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;

final class ListExpensePeriodsService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpensePeriodRepository $periods,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(ExpenseActor $actor, ?string $country = null): array
    {
        $this->access->requireFinance($actor);

        $countryCode = $country !== null && trim($country) !== ''
            ? strtoupper(trim($country))
            : null;

        $out = [];
        foreach ($this->periods->listByCountry($countryCode) as $period) {
            $out[] = ExpenseFinanceViews::period($period);
        }

        return $out;
    }
}
