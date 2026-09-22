<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery;

final class AzureSqlExpenseProfileAdminQuery implements ExpenseProfileAdminQuery
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listActiveProfiles(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    p.[UserId] AS userId,
    LOWER(CONVERT(NVARCHAR(36), u.[PublicId])) AS publicId,
    u.[DisplayName] AS displayName,
    u.[EmailAddress] AS email,
    p.[LegacyLogin] AS legacyLogin
FROM [expense].[ExpenseProfiles] p
INNER JOIN [identity].[Users] u ON u.[UserId] = p.[UserId]
WHERE p.[IsActive] = 1
ORDER BY u.[DisplayName], u.[EmailAddress]
SQL
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'userId' => AzureSqlValueMapper::intValue($row['userId'] ?? 0),
                'publicId' => AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
                'displayName' => AzureSqlValueMapper::stringValue($row['displayName'] ?? ''),
                'email' => AzureSqlValueMapper::stringValue($row['email'] ?? ''),
                'legacyLogin' => AzureSqlValueMapper::stringValue($row['legacyLogin'] ?? ''),
            ];
        }

        return $out;
    }
}
