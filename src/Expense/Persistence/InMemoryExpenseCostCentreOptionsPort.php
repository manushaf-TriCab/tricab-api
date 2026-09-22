<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseCostCentreOptionsPort;

/**
 * In-memory / test double for cost-centre selector options.
 */
final class InMemoryExpenseCostCentreOptionsPort implements ExpenseCostCentreOptionsPort
{
    /** @param list<string> $labels */
    public function __construct(
        private array $labels = [],
        private bool $available = true,
    ) {
    }

    public function listActiveLabels(): array
    {
        $out = [];
        foreach ($this->labels as $label) {
            $trimmed = trim($label);
            if ($trimmed !== '') {
                $out[$trimmed] = $trimmed;
            }
        }
        $values = array_values($out);
        sort($values, SORT_STRING | SORT_FLAG_CASE);

        return $values;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}
