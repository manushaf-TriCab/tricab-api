<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

/**
 * Generic read-only view of legacy TriExpense receipts for an authenticated
 * user's resolved legacy identity (login/email from ExpenseProfile).
 *
 * Never mutates MySQL. Ownership filters are applied in SQL.
 */
interface LegacyExpenseReadRepository
{
    /**
     * @param list<string> $ownerKeys lowercased login/email values that identify the owner
     * @return list<array<string, mixed>>
     */
    public function listForOwnerKeys(array $ownerKeys, int $limit = 500): array;

    /**
     * @param list<string> $ownerKeys
     * @return array<string, mixed>|null
     */
    public function findOwnedById(int $legacyReceiptId, array $ownerKeys): ?array;
}
