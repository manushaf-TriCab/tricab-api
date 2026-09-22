<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

/**
 * Read model for TriExpense Administration profile/approver UI.
 *
 * @return list<array{
 *   userId: int,
 *   publicId: string,
 *   displayName: string,
 *   email: string,
 *   legacyLogin: string
 * }>
 */
interface ExpenseProfileAdminQuery
{
    /** @return list<array{userId: int, publicId: string, displayName: string, email: string, legacyLogin: string}> */
    public function listActiveProfiles(): array;
}
