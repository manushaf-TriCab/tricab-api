<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Contract\LegacyExpenseReadRepository;

/** Used when legacy read is disabled or TC MySQL is unavailable. */
final class NullLegacyExpenseReadRepository implements LegacyExpenseReadRepository
{
    public function listForOwnerKeys(array $ownerKeys, int $limit = 500): array
    {
        return [];
    }

    public function findOwnedById(int $legacyReceiptId, array $ownerKeys): ?array
    {
        return null;
    }
}
