<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

/**
 * Read-only legacy TriExpense user directory for dry-run approver mapping status.
 *
 * Implementations must never write to MySQL.
 *
 * @return list<array{
 *   login_norm: string,
 *   email_norm: string,
 *   is_active: int|bool,
 *   manager_login: string
 * }>
 */
interface ExpenseLegacyTeUserDirectoryPort
{
    /**
     * @return list<array{login_norm: string, email_norm: string, is_active: int|bool, manager_login: string}>
     */
    public function listActiveDirectory(): array;

    public function isAvailable(): bool;
}
