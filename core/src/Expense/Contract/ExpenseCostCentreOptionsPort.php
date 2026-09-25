<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

/**
 * Active TriExpense cost-centre option labels for client selectors.
 *
 * Labels match legacy TriExpense storage: "{Department}, {OfficeLocation}".
 * The label string is what is persisted on ExpenseReceipts.CostCentre.
 */
interface ExpenseCostCentreOptionsPort
{
    /**
     * @return list<string> unique non-empty labels, sorted
     */
    public function listActiveLabels(): array;

    /**
     * True when the underlying catalog source is reachable.
     * False means clients should treat an empty list as unavailable, not empty.
     */
    public function isAvailable(): bool;
}
