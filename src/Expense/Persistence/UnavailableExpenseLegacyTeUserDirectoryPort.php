<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseLegacyTeUserDirectoryPort;

/** In-memory / test stub — no legacy MySQL. */
final class UnavailableExpenseLegacyTeUserDirectoryPort implements ExpenseLegacyTeUserDirectoryPort
{
    public function listActiveDirectory(): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
