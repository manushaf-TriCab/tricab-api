<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\LegacyMigration\Contract\TriCabRoleCatalogLookup;

/** SELECT-only. Never writes to identity.Roles. */
final class AzureSqlTriCabRoleCatalogLookup implements TriCabRoleCatalogLookup
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function activeRoles(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [RoleCode], [RoleName], [IsActive]
FROM [identity].[Roles]
WHERE [IsActive] = 1
ORDER BY [RoleCode] ASC
SQL,
        );
        $out = [];
        foreach ($rows as $row) {
            $code = strtoupper(AzureSqlValueMapper::stringValue($row['RoleCode'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[] = [
                'roleCode' => $code,
                'roleName' => AzureSqlValueMapper::stringValue($row['RoleName'] ?? $code),
                'isActive' => AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            ];
        }

        return $out;
    }
}
