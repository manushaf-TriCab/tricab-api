<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Contract\ExpenseCostCentreOptionsPort;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

/**
 * Parity with legacy te_get_cost_centre_options(): distinct
 * "{department}, {office_location}" labels from active TriExpense users.
 *
 * Department = COALESCE(department_override, employee.department).
 */
final class MysqlLegacyExpenseCostCentreOptionsPort implements ExpenseCostCentreOptionsPort
{
    private const SQL = <<<'SQL'
SELECT DISTINCT
    COALESCE(NULLIF(TRIM(u.department_override), ''), TRIM(e.department), '') AS dept,
    COALESCE(TRIM(u.office_location), '') AS branch
FROM tc.triexpense_users u
LEFT JOIN tc.employee e ON LOWER(e.user_login) = LOWER(u.user_login)
WHERE u.is_active = 1
ORDER BY dept, branch
SQL;

    public function __construct(
        private readonly LegacyReadOnlyConnection $connection,
        private readonly LegacyReadOnlySqlGuard $guard,
    ) {
    }

    public function listActiveLabels(): array
    {
        $this->guard->assertReadOnly(self::SQL);
        $rows = $this->connection->query(self::SQL, [], 'MysqlLegacyExpenseCostCentreOptionsPort', 'listActiveLabels');

        $labels = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dept = trim((string) ($row['dept'] ?? ''));
            $branch = trim((string) ($row['branch'] ?? ''));
            $parts = array_values(array_filter([$dept, $branch], static fn (string $part): bool => $part !== ''));
            if ($parts === []) {
                continue;
            }
            $label = implode(', ', $parts);
            $labels[$label] = $label;
        }

        $values = array_values($labels);
        sort($values, SORT_STRING | SORT_FLAG_CASE);

        return $values;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
