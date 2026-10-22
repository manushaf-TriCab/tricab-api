<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionEntry;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionWrite;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSession;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSessionSummary;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

/**
 * Azure SQL implementation of the Core ActAsSessionRepository contract.
 * Persists into [audit].[ActAsSessions] and [audit].[ActAsActions].
 */
final class AzureSqlActAsSessionRepository implements ActAsSessionRepository
{
    private const MAX_CLIENT_IP = 64;
    private const MAX_USER_AGENT = 512;
    private const MAX_ROUTE_PATTERN = 400;
    private const MAX_PATH = 2000;
    private const MAX_REQUEST_ID = 100;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findUserIdByPublicId(UserPublicId $publicId): ?UserId
    {
        $rows = $this->connection->fetchAll(
            'SELECT [UserId] FROM [identity].[Users] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : UserId::fromInt(AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? null));
    }

    public function create(
        string $sessionId,
        UserId $adminUserId,
        UserId $targetUserId,
        string $reason,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $expiresAt,
        ?string $clientIp,
        ?string $userAgent,
    ): ActAsSession {
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [audit].[ActAsSessions] (
    [Id],
    [AdminUserId],
    [TargetUserId],
    [Reason],
    [StartedAt],
    [ExpiresAt],
    [ClientIp],
    [UserAgent]
)
VALUES (
    :id,
    :adminUserId,
    :targetUserId,
    :reason,
    :startedAt,
    :expiresAt,
    :clientIp,
    :userAgent
)
SQL,
            [
                ':id' => $sessionId,
                ':adminUserId' => $adminUserId->toInt(),
                ':targetUserId' => $targetUserId->toInt(),
                ':reason' => $reason,
                ':startedAt' => self::offsetValue($startedAt),
                ':expiresAt' => self::offsetValue($expiresAt),
                ':clientIp' => $clientIp === null ? null : substr($clientIp, 0, self::MAX_CLIENT_IP),
                ':userAgent' => $userAgent === null ? null : substr($userAgent, 0, self::MAX_USER_AGENT),
            ],
        );

        return new ActAsSession(
            $sessionId,
            $adminUserId->toInt(),
            $targetUserId->toInt(),
            $reason,
            $startedAt,
            $expiresAt,
        );
    }

    public function findById(string $sessionId): ?ActAsSession
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), [Id]) AS [Id],
    [AdminUserId],
    [TargetUserId],
    [Reason],
    CONVERT(VARCHAR(33), [StartedAt], 127) AS [StartedAt],
    CONVERT(VARCHAR(33), [ExpiresAt], 127) AS [ExpiresAt],
    CONVERT(VARCHAR(33), [EndedAt], 127) AS [EndedAt],
    [EndReason]
FROM [audit].[ActAsSessions]
WHERE [Id] = :id
SQL,
            [':id' => $sessionId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $endedAt = AzureSqlValueMapper::nullableDateTime($row['EndedAt'] ?? null);

        return new ActAsSession(
            strtolower(AzureSqlValueMapper::stringValue($row['Id'] ?? null)),
            AzureSqlValueMapper::intValue($row['AdminUserId'] ?? null),
            AzureSqlValueMapper::intValue($row['TargetUserId'] ?? null),
            AzureSqlValueMapper::stringValue($row['Reason'] ?? null),
            AzureSqlValueMapper::requireDateTime($row['StartedAt'] ?? null, 'StartedAt'),
            AzureSqlValueMapper::requireDateTime($row['ExpiresAt'] ?? null, 'ExpiresAt'),
            $endedAt,
            AzureSqlValueMapper::nullableString($row['EndReason'] ?? null),
        );
    }

    public function end(string $sessionId, string $endReason, DateTimeImmutable $endedAt): bool
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [audit].[ActAsSessions]
SET [EndedAt] = :endedAt,
    [EndReason] = :endReason
OUTPUT INSERTED.[AdminUserId] AS [AdminUserId]
WHERE [Id] = :id
  AND [EndedAt] IS NULL
SQL,
            [
                ':endedAt' => self::offsetValue($endedAt),
                ':endReason' => $endReason,
                ':id' => $sessionId,
            ],
        );

        return $rows !== [];
    }

    public function appendAction(ActAsActionWrite $action): void
    {
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [audit].[ActAsActions] (
    [SessionId],
    [AdminUserId],
    [TargetUserId],
    [Method],
    [RoutePattern],
    [Path],
    [StatusCode],
    [DurationMs],
    [RequestId],
    [OccurredAt]
)
VALUES (
    :sessionId,
    :adminUserId,
    :targetUserId,
    :method,
    :routePattern,
    :path,
    :statusCode,
    :durationMs,
    :requestId,
    :occurredAt
)
SQL,
            [
                ':sessionId' => $action->sessionId,
                ':adminUserId' => $action->adminUserId,
                ':targetUserId' => $action->targetUserId,
                ':method' => $action->method,
                ':routePattern' => substr($action->routePattern, 0, self::MAX_ROUTE_PATTERN),
                ':path' => substr($action->path, 0, self::MAX_PATH),
                ':statusCode' => $action->statusCode,
                ':durationMs' => $action->durationMs,
                ':requestId' => $action->requestId === null ? null : substr($action->requestId, 0, self::MAX_REQUEST_ID),
                ':occurredAt' => self::offsetValue($action->occurredAt),
            ],
        );
    }

    public function listRecent(int $limit): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), s.[Id]) AS [Id],
    s.[Reason],
    CONVERT(VARCHAR(33), s.[StartedAt], 127) AS [StartedAt],
    CONVERT(VARCHAR(33), s.[ExpiresAt], 127) AS [ExpiresAt],
    CONVERT(VARCHAR(33), s.[EndedAt], 127) AS [EndedAt],
    s.[EndReason],
    CONVERT(NVARCHAR(36), au.[PublicId]) AS [AdminPublicId],
    au.[DisplayName] AS [AdminDisplayName],
    au.[EmailAddress] AS [AdminEmailAddress],
    CONVERT(NVARCHAR(36), tu.[PublicId]) AS [TargetPublicId],
    tu.[DisplayName] AS [TargetDisplayName],
    tu.[EmailAddress] AS [TargetEmailAddress]
FROM [audit].[ActAsSessions] s
INNER JOIN [identity].[Users] au ON au.[UserId] = s.[AdminUserId]
INNER JOIN [identity].[Users] tu ON tu.[UserId] = s.[TargetUserId]
ORDER BY s.[StartedAt] DESC
OFFSET 0 ROWS FETCH NEXT :limit ROWS ONLY
SQL,
            [':limit' => $limit],
        );

        return array_map(
            static fn (array $row): ActAsSessionSummary => new ActAsSessionSummary(
                sessionId: strtolower(AzureSqlValueMapper::stringValue($row['Id'] ?? null)),
                reason: AzureSqlValueMapper::stringValue($row['Reason'] ?? null),
                startedAt: self::iso($row['StartedAt'] ?? null, 'StartedAt'),
                expiresAt: self::iso($row['ExpiresAt'] ?? null, 'ExpiresAt'),
                endedAt: AzureSqlValueMapper::nullableString($row['EndedAt'] ?? null) === null
                    ? null
                    : self::iso($row['EndedAt'] ?? null, 'EndedAt'),
                endReason: AzureSqlValueMapper::nullableString($row['EndReason'] ?? null),
                adminPublicId: strtolower(AzureSqlValueMapper::stringValue($row['AdminPublicId'] ?? null)),
                adminDisplayName: AzureSqlValueMapper::stringValue($row['AdminDisplayName'] ?? null),
                adminEmailAddress: AzureSqlValueMapper::stringValue($row['AdminEmailAddress'] ?? null),
                targetPublicId: strtolower(AzureSqlValueMapper::stringValue($row['TargetPublicId'] ?? null)),
                targetDisplayName: AzureSqlValueMapper::stringValue($row['TargetDisplayName'] ?? null),
                targetEmailAddress: AzureSqlValueMapper::stringValue($row['TargetEmailAddress'] ?? null),
            ),
            $rows,
        );
    }

    public function listActions(string $sessionId, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    [Id],
    CONVERT(NVARCHAR(36), [SessionId]) AS [SessionId],
    [Method],
    [RoutePattern],
    [Path],
    [StatusCode],
    [DurationMs],
    [RequestId],
    CONVERT(VARCHAR(33), [OccurredAt], 127) AS [OccurredAt]
FROM [audit].[ActAsActions]
WHERE [SessionId] = :sessionId
ORDER BY [OccurredAt] ASC, [Id] ASC
OFFSET 0 ROWS FETCH NEXT :limit ROWS ONLY
SQL,
            [':sessionId' => $sessionId, ':limit' => $limit],
        );

        return array_map(
            static fn (array $row): ActAsActionEntry => new ActAsActionEntry(
                id: AzureSqlValueMapper::intValue($row['Id'] ?? null),
                sessionId: strtolower(AzureSqlValueMapper::stringValue($row['SessionId'] ?? null)),
                method: AzureSqlValueMapper::stringValue($row['Method'] ?? null),
                routePattern: AzureSqlValueMapper::stringValue($row['RoutePattern'] ?? null),
                path: AzureSqlValueMapper::stringValue($row['Path'] ?? null),
                statusCode: AzureSqlValueMapper::intValue($row['StatusCode'] ?? null),
                durationMs: AzureSqlValueMapper::intValue($row['DurationMs'] ?? null),
                requestId: AzureSqlValueMapper::nullableString($row['RequestId'] ?? null),
                occurredAt: self::iso($row['OccurredAt'] ?? null, 'OccurredAt'),
            ),
            $rows,
        );
    }

    /**
     * Audit times are stored in Melbourne time, with its offset (+10:00 or +11:00).
     */
    private static function zone(): DateTimeZone
    {
        return new DateTimeZone('Australia/Melbourne');
    }

    private static function offsetValue(DateTimeImmutable $value): string
    {
        return $value->setTimezone(self::zone())->format('Y-m-d\TH:i:s.uP');
    }

    private static function iso(mixed $value, string $field): string
    {
        return AzureSqlValueMapper::requireDateTime($value, $field)->setTimezone(self::zone())->format(DATE_ATOM);
    }
}
