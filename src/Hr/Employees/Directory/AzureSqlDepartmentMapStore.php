<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/** `[hr].[DepartmentMap]` on Azure SQL; every change writes `[audit].[HrDepartmentMapEvents]` in the same transaction. */
final class AzureSqlDepartmentMapStore implements DepartmentMapStore
{
    use AzureSqlTransaction;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function entries(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT COALESCE(s.[Kind], m.[MapKind]) AS [Kind],
                   COALESCE(s.[Raw], m.[RawValue]) AS [Raw],
                   m.[CleanName], m.[CostCentre],
                   COALESCE(s.[Employees], 0) AS [Employees],
                   COALESCE(s.[CurrentEmployees], 0) AS [CurrentEmployees],
                   CONVERT(VARCHAR(33), m.[UpdatedAtUtc], 127) AS [UpdatedAt],
                   u.[DisplayName] AS [UpdatedBy]
            FROM (
                SELECT N'department' AS [Kind], LTRIM(RTRIM([DepartmentRaw])) AS [Raw],
                       COUNT(*) AS [Employees], SUM(CAST([IsCurrent] AS INT)) AS [CurrentEmployees]
                FROM [hr].[Employees]
                WHERE NULLIF(LTRIM(RTRIM([DepartmentRaw])), N'') IS NOT NULL
                GROUP BY LTRIM(RTRIM([DepartmentRaw]))
                UNION ALL
                SELECT N'factory', LTRIM(RTRIM([FactoryRaw])),
                       COUNT(*), SUM(CAST([IsCurrent] AS INT))
                FROM [hr].[Employees]
                WHERE NULLIF(LTRIM(RTRIM([FactoryRaw])), N'') IS NOT NULL
                GROUP BY LTRIM(RTRIM([FactoryRaw]))
            ) s
            FULL OUTER JOIN [hr].[DepartmentMap] m ON m.[MapKind] = s.[Kind] AND m.[RawValue] = s.[Raw]
            LEFT JOIN [identity].[Users] u ON u.[UserId] = m.[UpdatedByUserId]
            ORDER BY [Kind], [Raw]
            SQL,
        );

        return array_map(static function (array $row): array {
            $clean = AzureSqlValueMapper::nullableString($row['CleanName'] ?? null);

            return [
                'kind' => AzureSqlValueMapper::stringValue($row['Kind'] ?? ''),
                'raw' => AzureSqlValueMapper::stringValue($row['Raw'] ?? ''),
                'cleanName' => $clean,
                'costCentre' => AzureSqlValueMapper::nullableString($row['CostCentre'] ?? null),
                'mapped' => $clean !== null,
                'employees' => AzureSqlValueMapper::intValue($row['Employees'] ?? 0),
                'currentEmployees' => AzureSqlValueMapper::intValue($row['CurrentEmployees'] ?? 0),
                'updatedAt' => AzureSqlValueMapper::nullableString($row['UpdatedAt'] ?? null),
                'updatedBy' => AzureSqlValueMapper::nullableString($row['UpdatedBy'] ?? null),
            ];
        }, $rows);
    }

    public function save(ActorContext $actor, string $kind, string $raw, ?string $cleanName, ?string $costCentre): array
    {
        return $this->runInTransaction($this->connection, function () use ($actor, $kind, $raw, $cleanName, $costCentre): array {
            $old = $this->connection->fetchAll(
                'SELECT [CleanName], [CostCentre] FROM [hr].[DepartmentMap] WITH (UPDLOCK, HOLDLOCK) WHERE [MapKind] = :kind AND [RawValue] = :raw',
                [':kind' => $kind, ':raw' => $raw],
            )[0] ?? null;

            if ($old === null && !$this->seenInData($kind, $raw)) {
                throw DepartmentMapRefused::unknownValue($kind, $raw);
            }

            $oldClean = $old === null ? null : AzureSqlValueMapper::nullableString($old['CleanName'] ?? null);
            $oldCost = $old === null ? null : AzureSqlValueMapper::nullableString($old['CostCentre'] ?? null);
            $result = ['kind' => $kind, 'raw' => $raw, 'cleanName' => $cleanName, 'costCentre' => $costCentre, 'mapped' => $cleanName !== null];

            if ($old === null && $cleanName === null) {
                return [...$result, 'changed' => false];
            }
            if ($old !== null && $oldClean === $cleanName && $oldCost === $costCentre) {
                return [...$result, 'changed' => false];
            }

            if ($cleanName === null) {
                $action = 'cleared';
                $this->connection->execute(
                    'DELETE FROM [hr].[DepartmentMap] WHERE [MapKind] = :kind AND [RawValue] = :raw',
                    [':kind' => $kind, ':raw' => $raw],
                );
            } elseif ($old === null) {
                $action = 'created';
                $this->connection->execute(
                    'INSERT INTO [hr].[DepartmentMap] ([MapKind], [RawValue], [CleanName], [CostCentre], [UpdatedByUserId])
                     VALUES (:kind, :raw, :clean, :cost, :actor)',
                    [':kind' => $kind, ':raw' => $raw, ':clean' => $cleanName, ':cost' => $costCentre, ':actor' => $actor->userId],
                );
            } else {
                $action = 'updated';
                $this->connection->execute(
                    'UPDATE [hr].[DepartmentMap]
                     SET [CleanName] = :clean, [CostCentre] = :cost, [UpdatedByUserId] = :actor, [UpdatedAtUtc] = SYSDATETIMEOFFSET()
                     WHERE [MapKind] = :kind AND [RawValue] = :raw',
                    [':clean' => $cleanName, ':cost' => $costCentre, ':actor' => $actor->userId, ':kind' => $kind, ':raw' => $raw],
                );
            }

            $this->connection->execute(
                <<<'SQL'
                INSERT INTO [audit].[HrDepartmentMapEvents] (
                    [ActorUserId], [MapKind], [RawValue], [Action],
                    [OldCleanName], [OldCostCentre], [NewCleanName], [NewCostCentre],
                    [PermissionCode], [RequestCorrelationId], [IpAddress], [UserAgent]
                ) VALUES (
                    :actorUserId, :kind, :raw, :action,
                    :oldClean, :oldCost, :newClean, :newCost,
                    :permissionCode, :correlationId, :ipAddress, :userAgent
                )
                SQL,
                [
                    ':actorUserId' => $actor->userId,
                    ':kind' => $kind,
                    ':raw' => $raw,
                    ':action' => $action,
                    ':oldClean' => $oldClean,
                    ':oldCost' => $oldCost,
                    ':newClean' => $cleanName,
                    ':newCost' => $costCentre,
                    ':permissionCode' => HrPermissions::EMPLOYEES_SYNC,
                    ':correlationId' => $actor->correlationId === null ? null : mb_substr($actor->correlationId, 0, 100),
                    ':ipAddress' => $actor->ipAddress === null ? null : mb_substr($actor->ipAddress, 0, 64),
                    ':userAgent' => $actor->userAgent === null ? null : mb_substr($actor->userAgent, 0, 500),
                ],
            );

            return [...$result, 'changed' => true];
        });
    }

    private function seenInData(string $kind, string $raw): bool
    {
        $column = $kind === self::KIND_DEPARTMENT ? 'DepartmentRaw' : 'FactoryRaw';
        $rows = $this->connection->fetchAll(
            "SELECT TOP (1) 1 AS [Seen] FROM [hr].[Employees] WHERE LTRIM(RTRIM([{$column}])) = :raw",
            [':raw' => $raw],
        );

        return $rows !== [];
    }
}
