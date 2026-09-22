<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Contract\ExpenseCostCentreOptionsPort;

/**
 * Cost-centre catalog unavailable (legacy MySQL not wired or feature off).
 * Clients must show an unavailable/error state, not "no cost centres".
 */
final class UnavailableExpenseCostCentreOptionsPort implements ExpenseCostCentreOptionsPort
{
    public function listActiveLabels(): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
