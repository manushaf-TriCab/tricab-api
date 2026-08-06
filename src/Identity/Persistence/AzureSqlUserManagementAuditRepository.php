<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\Pagination;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditEntry;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditListResult;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditWrite;
use TriCab\Core\Identity\Contract\UserManagementAuditRepository;

/**
 * Azure SQL implementation of the Core UserManagementAuditRepository contract.
 * Persists into [audit].[UserManagementEvents]; previous/new values are
 * JSON-encoded exactly as supplied by the caller (redaction is the Core
 * recorder's responsibility, not this repository's).
 */
final class AzureSqlUserManagementAuditRepository implements UserManagementAuditRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function append(UserManagementAuditWrite $entry): UserManagementAuditEntry
    {
        $lookupRows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), au.[PublicId]) AS [ActorPublicId],
    CONVERT(NVARCHAR(36), tu.[PublicId]) AS [TargetUserPublicId],
    tr.[RoleCode] AS [TargetRoleCode],
    tp.[PermissionCode] AS [TargetPermissionCode]
FROM [identity].[Users] au
LEFT JOIN [identity].[Users] tu ON tu.[UserId] = :targetUserId
LEFT JOIN [identity].[Roles] tr ON tr.[RoleId] = :targetRoleId
LEFT JOIN [identity].[Permissions] tp ON tp.[PermissionId] = :targetPermissionId
WHERE au.[UserId] = :actorUserId
SQL,
            [
                ':actorUserId' => $entry->actorUserId,
                ':targetUserId' => $entry->targetUserId,
                ':targetRoleId' => $entry->targetRoleId,
                ':targetPermissionId' => $entry->targetPermissionId,
            ],
        );

        if ($lookupRows === []) {
            throw IdentityPersistenceException::userNotFound((string) $entry->actorUserId);
        }

        $lookup = $lookupRows[0];

        $insertRows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [audit].[UserManagementEvents] (
    [ActorUserId],
    [TargetUserId],
    [TargetRoleId],
    [TargetPermissionId],
    [EventType],
    [EventSummary],
    [PreviousValuesJson],
    [NewValuesJson],
    [RequestCorrelationId],
    [IpAddress],
    [UserAgent]
)
OUTPUT
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    CONVERT(VARCHAR(33), INSERTED.[CreatedAtUtc], 127) AS [CreatedAtUtc]
VALUES (
    :actorUserId,
    :targetUserId,
    :targetRoleId,
    :targetPermissionId,
    :eventType,
    :eventSummary,
    :previousValuesJson,
    :newValuesJson,
    :correlationId,
    :ipAddress,
    :userAgent
)
SQL,
            [
                ':actorUserId' => $entry->actorUserId,
                ':targetUserId' => $entry->targetUserId,
                ':targetRoleId' => $entry->targetRoleId,
                ':targetPermissionId' => $entry->targetPermissionId,
                ':eventType' => $entry->eventType,
                ':eventSummary' => $entry->eventSummary,
                ':previousValuesJson' => $entry->previousValues !== null ? json_encode($entry->previousValues) : null,
                ':newValuesJson' => $entry->newValues !== null ? json_encode($entry->newValues) : null,
                ':correlationId' => $entry->correlationId,
                ':ipAddress' => $entry->ipAddress,
                ':userAgent' => $entry->userAgent,
            ],
        );

        if ($insertRows === []) {
            throw IdentityPersistenceException::insertFailed('UserManagementEvent');
        }

        $inserted = $insertRows[0];

        return new UserManagementAuditEntry(
            publicId: AzureSqlValueMapper::stringValue($inserted['PublicId'] ?? null),
            eventType: $entry->eventType,
            eventSummary: $entry->eventSummary,
            actorPublicId: AzureSqlValueMapper::stringValue($lookup['ActorPublicId'] ?? null),
            targetUserPublicId: AzureSqlValueMapper::nullableString($lookup['TargetUserPublicId'] ?? null),
            targetRoleCode: AzureSqlValueMapper::nullableString($lookup['TargetRoleCode'] ?? null),
            targetPermissionCode: AzureSqlValueMapper::nullableString($lookup['TargetPermissionCode'] ?? null),
            previousValues: $entry->previousValues,
            newValues: $entry->newValues,
            createdAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($inserted['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            ),
            correlationId: $entry->correlationId,
        );
    }

    public function list(UserManagementAuditCriteria $criteria): UserManagementAuditListResult
    {
        $conditions = [];
        $params = [];

        if ($criteria->targetUserPublicId !== null) {
            $conditions[] = 'tu.[PublicId] = :targetUserPublicId';
            $params[':targetUserPublicId'] = $criteria->targetUserPublicId;
        }

        if ($criteria->targetRoleCode !== null) {
            $conditions[] = 'UPPER(tr.[RoleCode]) = :targetRoleCode';
            $params[':targetRoleCode'] = strtoupper($criteria->targetRoleCode);
        }

        if ($criteria->eventType !== null) {
            $conditions[] = 'e.[EventType] = :eventType';
            $params[':eventType'] = strtoupper($criteria->eventType);
        }

        $where = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

        $countRows = $this->connection->fetchAll(
            <<<SQL
SELECT COUNT(*) AS [Total]
FROM [audit].[UserManagementEvents] e
INNER JOIN [identity].[Users] au ON au.[UserId] = e.[ActorUserId]
LEFT JOIN [identity].[Users] tu ON tu.[UserId] = e.[TargetUserId]
LEFT JOIN [identity].[Roles] tr ON tr.[RoleId] = e.[TargetRoleId]
LEFT JOIN [identity].[Permissions] tp ON tp.[PermissionId] = e.[TargetPermissionId]
WHERE 1 = 1{$where}
SQL,
            $params,
        );
        $total = $countRows === [] ? 0 : AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        $offset = ($criteria->page - 1) * $criteria->pageSize;

        $rows = $this->connection->fetchAll(
            self::listSelectSql() . <<<SQL

WHERE 1 = 1{$where}
ORDER BY e.[CreatedAtUtc] DESC
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
SQL,
            [...$params, ':offset' => $offset, ':pageSize' => $criteria->pageSize],
        );

        $items = array_map(fn (array $row): UserManagementAuditEntry => $this->hydrateEntry($row), $rows);

        return new UserManagementAuditListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function listRecentForUser(string $userPublicId, int $limit = 10): array
    {
        if ($limit < 1) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            self::listSelectSql() . <<<'SQL'

WHERE tu.[PublicId] = :targetUserPublicId
ORDER BY e.[CreatedAtUtc] DESC
OFFSET 0 ROWS FETCH NEXT :limit ROWS ONLY
SQL,
            [':targetUserPublicId' => $userPublicId, ':limit' => $limit],
        );

        return array_map(fn (array $row): UserManagementAuditEntry => $this->hydrateEntry($row), $rows);
    }

    private static function listSelectSql(): string
    {
        return <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), e.[PublicId]) AS [PublicId],
    e.[EventType],
    e.[EventSummary],
    CONVERT(NVARCHAR(36), au.[PublicId]) AS [ActorPublicId],
    CONVERT(NVARCHAR(36), tu.[PublicId]) AS [TargetUserPublicId],
    tr.[RoleCode] AS [TargetRoleCode],
    tp.[PermissionCode] AS [TargetPermissionCode],
    e.[PreviousValuesJson],
    e.[NewValuesJson],
    CONVERT(VARCHAR(33), e.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    e.[RequestCorrelationId]
FROM [audit].[UserManagementEvents] e
INNER JOIN [identity].[Users] au ON au.[UserId] = e.[ActorUserId]
LEFT JOIN [identity].[Users] tu ON tu.[UserId] = e.[TargetUserId]
LEFT JOIN [identity].[Roles] tr ON tr.[RoleId] = e.[TargetRoleId]
LEFT JOIN [identity].[Permissions] tp ON tp.[PermissionId] = e.[TargetPermissionId]
SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateEntry(array $row): UserManagementAuditEntry
    {
        return new UserManagementAuditEntry(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            eventType: AzureSqlValueMapper::stringValue($row['EventType'] ?? null),
            eventSummary: AzureSqlValueMapper::stringValue($row['EventSummary'] ?? null),
            actorPublicId: AzureSqlValueMapper::stringValue($row['ActorPublicId'] ?? null),
            targetUserPublicId: AzureSqlValueMapper::nullableString($row['TargetUserPublicId'] ?? null),
            targetRoleCode: AzureSqlValueMapper::nullableString($row['TargetRoleCode'] ?? null),
            targetPermissionCode: AzureSqlValueMapper::nullableString($row['TargetPermissionCode'] ?? null),
            previousValues: $this->decodeJson($row['PreviousValuesJson'] ?? null),
            newValues: $this->decodeJson($row['NewValuesJson'] ?? null),
            createdAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            ),
            correlationId: AzureSqlValueMapper::nullableString($row['RequestCorrelationId'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        $json = AzureSqlValueMapper::nullableString($value);
        if ($json === null) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $result = [];
        foreach ($decoded as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
