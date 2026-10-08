<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Access;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMap;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMapping;

/**
 * `finance.portal_user_map` (`create-finance-access-schema.sql`). A mapping is added
 * by one statement that refuses to insert where the portal user already has a row or
 * the platform user is already mapped; the primary key and the unique index on
 * `platform_user_id` settle two writers that race past it.
 */
final class AzureSqlFinancePortalUserMap implements FinancePortalUserMap
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function platformUserIdFor(string $portalUserId): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [platform_user_id] FROM [finance].[portal_user_map] WHERE [portal_user_id] = :id',
            ['id' => $portalUserId],
        );

        return $rows === [] ? null : AzureSqlValueMapper::intValue($rows[0]['platform_user_id']);
    }

    public function all(): array
    {
        $out = [];
        foreach ($this->connection->fetchAll(
            'SELECT [portal_user_id], [recorded_email], [platform_user_id], [mapped_at] FROM [finance].[portal_user_map] ORDER BY [portal_user_id]',
        ) as $row) {
            $mapping = new FinancePortalUserMapping(
                AzureSqlValueMapper::stringValue($row['portal_user_id']),
                AzureSqlValueMapper::stringValue($row['recorded_email']),
                AzureSqlValueMapper::intValue($row['platform_user_id']),
                AzureSqlValueMapper::requireDateTime($row['mapped_at'], 'mapped_at'),
            );
            $out[$mapping->portalUserId] = $mapping;
        }

        return $out;
    }

    public function add(FinancePortalUserMapping $mapping): bool
    {
        // OUTPUT says whether the row went in; a lost race surfaces as a key violation, which is the same answer.
        try {
            return $this->connection->fetchAll(
                'INSERT INTO [finance].[portal_user_map] ([portal_user_id], [recorded_email], [platform_user_id], [mapped_at])
                 OUTPUT inserted.[portal_user_id]
                 SELECT :id, :email, :platform, :at
                  WHERE NOT EXISTS (SELECT 1 FROM [finance].[portal_user_map] WHERE [portal_user_id] = :id2 OR [platform_user_id] = :platform2)',
                [
                    'id' => $mapping->portalUserId,
                    'email' => $mapping->recordedEmail,
                    'platform' => $mapping->platformUserId,
                    'at' => AzureSqlValueMapper::formatDateTime($mapping->mappedAt),
                    'id2' => $mapping->portalUserId,
                    'platform2' => $mapping->platformUserId,
                ],
            ) !== [];
        } catch (\TriCab\Api\Infrastructure\Database\DatabaseUnavailable $failure) {
            if ($this->platformUserIdFor($mapping->portalUserId) !== null || $this->isPlatformUserMapped($mapping->platformUserId)) {
                return false;
            }
            throw $failure;
        }
    }

    private function isPlatformUserMapped(int $platformUserId): bool
    {
        return $this->connection->fetchAll(
            'SELECT 1 AS [mapped] FROM [finance].[portal_user_map] WHERE [platform_user_id] = :id',
            ['id' => $platformUserId],
        ) !== [];
    }
}
