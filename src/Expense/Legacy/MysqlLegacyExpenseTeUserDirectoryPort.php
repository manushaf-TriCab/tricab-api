<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\ExpenseLegacyTeUserDirectoryPort;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

/**
 * Read-only tc.triexpense_users listing for dry-run mapping status.
 * ZERO writes — guarded by LegacyReadOnlySqlGuard.
 */
final class MysqlLegacyExpenseTeUserDirectoryPort implements ExpenseLegacyTeUserDirectoryPort
{
    public function __construct(
        private readonly LegacyReadOnlyConnection $connection,
        private readonly LegacyReadOnlySqlGuard $guard,
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function listActiveDirectory(): array
    {
        $sql = 'SELECT LOWER(TRIM(user_login)) AS login_norm, '
            . 'LOWER(TRIM(user_email)) AS email_norm, '
            . 'is_active, '
            . 'LOWER(TRIM(COALESCE(manager_login,\'\'))) AS manager_login '
            . 'FROM tc.triexpense_users ORDER BY id';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query(
            $sql,
            [],
            'MysqlLegacyExpenseTeUserDirectoryPort',
            'listActiveDirectory',
        );
        $out = [];
        foreach ($rows as $row) {
            $isActiveRaw = $row['is_active'] ?? 0;
            $isActive = is_bool($isActiveRaw)
                ? ($isActiveRaw ? 1 : 0)
                : MixedValueCoercion::intValue($isActiveRaw);
            $out[] = [
                'login_norm' => MixedValueCoercion::stringValue($row['login_norm'] ?? null),
                'email_norm' => MixedValueCoercion::stringValue($row['email_norm'] ?? null),
                'is_active' => $isActive,
                'manager_login' => MixedValueCoercion::stringValue($row['manager_login'] ?? null),
            ];
        }

        return $out;
    }
}
