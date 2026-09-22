<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;

final class ListExpensePeriodsService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpensePeriodRepository $periods,
        private readonly LegacyExpenseFinanceBankReadRepository $legacyBank,
        private readonly LegacyExpenseFinancePeriodAssembler $legacyPeriodAssembler,
        private readonly bool $legacyReadEnabled = false,
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

        if ($this->legacyReadEnabled) {
            foreach ($this->legacyBank->listPeriods($countryCode) as $row) {
                $out[] = $this->legacyPeriodAssembler->toFinanceView($row);
            }
        }

        usort($out, static function (array $a, array $b): int {
            $ea = MixedValueCoercion::stringValue($a['periodEnd'] ?? '');
            $eb = MixedValueCoercion::stringValue($b['periodEnd'] ?? '');
            if ($ea !== $eb) {
                return $eb <=> $ea;
            }

            return MixedValueCoercion::stringValue($a['publicId'] ?? '')
                <=> MixedValueCoercion::stringValue($b['publicId'] ?? '');
        });

        return $out;
    }
}
