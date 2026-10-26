<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

use PDO;

/**
 * The staff migration's own login to user mapping,
 * `[migration].[LegacyStaffUserMappings]` (the old WordPress `user_login` and
 * the platform `UserId`, the table the Staff to Entra link works from).
 * Logins match case-insensitively. Nothing is matched by email, and a login
 * the staff migration never mapped stays unlinked.
 */
final class StaffMappingUserLoginLinker implements UserLoginLinker
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function link(array $normalizedLogins): array
    {
        $present = $this->pdo->query("SELECT CASE WHEN OBJECT_ID(N'[migration].[LegacyStaffUserMappings]', N'U') IS NULL THEN 0 ELSE 1 END");
        if ($present === false || (int) EmployeeMapper::text($present->fetchColumn()) !== 1) {
            return [];
        }

        $wanted = array_flip($normalizedLogins);
        $linked = [];
        $rows = $this->pdo->query(
            'SELECT m.[LegacyUserLogin] AS login, m.[UserId] AS userId
             FROM [migration].[LegacyStaffUserMappings] m
             INNER JOIN [identity].[Users] u ON u.[UserId] = m.[UserId]',
        );
        while ($rows !== false && ($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $login = strtolower(trim((string) EmployeeMapper::text($row['login'] ?? null)));
            if ($login !== '' && isset($wanted[$login])) {
                $linked[$login] = (int) EmployeeMapper::text($row['userId'] ?? null);
            }
        }

        return $linked;
    }
}
