<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Domain\Entity\GuestSession;
use TriCab\Core\Identity\Domain\ValueObject\GuestSessionId;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

/**
 * Azure SQL persistence for guest sessions.
 */
final class AzureSqlGuestSessionRepository implements GuestSessionRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
SELECT
    s.[GuestSessionId],
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [PublicId],
    s.[UserId],
    s.[SessionTokenHash],
    CONVERT(NVARCHAR(36), s.[JwtId]) AS [JwtId],
    CONVERT(VARCHAR(27), s.[IssuedAtUtc], 121) AS [IssuedAtUtc],
    CONVERT(VARCHAR(27), s.[ExpiresAtUtc], 121) AS [ExpiresAtUtc],
    CONVERT(VARCHAR(27), s.[RevokedAtUtc], 121) AS [RevokedAtUtc],
    CONVERT(VARCHAR(27), s.[LastUsedAtUtc], 121) AS [LastUsedAtUtc],
    s.[CreatedIpAddress],
    s.[CreatedUserAgent],
    CONVERT(VARCHAR(27), s.[CreatedAtUtc], 121) AS [CreatedAtUtc]
FROM [identity].[GuestSessions] s
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function save(GuestSession $session): void
    {
        if ($session->id() === null) {
            $this->insert($session);

            return;
        }

        $this->update($session);
    }

    public function findById(GuestSessionId $id): ?GuestSession
    {
        $sql = self::SELECT_COLUMNS . ' WHERE s.[GuestSessionId] = :id';

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [':id' => $id->toInt()]),
        );
    }

    public function findByPublicId(UuidPublicId $publicId): ?GuestSession
    {
        $sql = self::SELECT_COLUMNS . ' WHERE s.[PublicId] = :publicId';

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [':publicId' => $publicId->toString()]),
        );
    }

    public function findBySessionTokenHash(SessionTokenHash $sessionTokenHash): ?GuestSession
    {
        $sql = self::SELECT_COLUMNS . ' WHERE s.[SessionTokenHash] = :sessionTokenHash';

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [
                ':sessionTokenHash' => $sessionTokenHash->toBinary(),
            ]),
        );
    }

    public function revokeActiveSessionsForUser(UserId $userId): void
    {
        $sql = <<<'SQL'
UPDATE [identity].[GuestSessions]
SET [RevokedAtUtc] = SYSUTCDATETIME()
WHERE [UserId] = :userId
  AND [RevokedAtUtc] IS NULL
  AND [ExpiresAtUtc] > SYSUTCDATETIME()
SQL;

        $this->connection->execute($sql, [':userId' => $userId->toInt()]);
    }

    private function insert(GuestSession $session): void
    {
        $sql = <<<'SQL'
INSERT INTO [identity].[GuestSessions] (
    [PublicId],
    [UserId],
    [SessionTokenHash],
    [JwtId],
    [IssuedAtUtc],
    [ExpiresAtUtc],
    [RevokedAtUtc],
    [LastUsedAtUtc],
    [CreatedIpAddress],
    [CreatedUserAgent],
    [CreatedAtUtc]
)
OUTPUT INSERTED.[GuestSessionId]
VALUES (
    :publicId,
    :userId,
    :sessionTokenHash,
    :jwtId,
    :issuedAtUtc,
    :expiresAtUtc,
    :revokedAtUtc,
    :lastUsedAtUtc,
    :createdIpAddress,
    :createdUserAgent,
    :createdAtUtc
)
SQL;

        $rows = $this->connection->fetchAll($sql, [
            ':publicId' => $session->publicId()->toString(),
            ':userId' => $session->userId()->toInt(),
            ':sessionTokenHash' => $session->sessionTokenHash()->toBinary(),
            ':jwtId' => $session->jwtId()?->toString(),
            ':issuedAtUtc' => AzureSqlValueMapper::formatDateTime($session->issuedAtUtc()),
            ':expiresAtUtc' => AzureSqlValueMapper::formatDateTime($session->expiresAtUtc()),
            ':revokedAtUtc' => $session->revokedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($session->revokedAtUtc()),
            ':lastUsedAtUtc' => $session->lastUsedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($session->lastUsedAtUtc()),
            ':createdIpAddress' => $session->createdIpAddress(),
            ':createdUserAgent' => $session->createdUserAgent(),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($session->createdAtUtc()),
        ]);

        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('GuestSession');
        }

        $session->assignId(
            GuestSessionId::fromInt(AzureSqlValueMapper::intValue($rows[0]['GuestSessionId'] ?? null)),
        );
    }

    private function update(GuestSession $session): void
    {
        $id = $session->id();
        if ($id === null) {
            throw IdentityPersistenceException::insertFailed('GuestSession');
        }

        $sql = <<<'SQL'
UPDATE [identity].[GuestSessions]
SET
    [RevokedAtUtc] = :revokedAtUtc,
    [LastUsedAtUtc] = :lastUsedAtUtc
WHERE [GuestSessionId] = :id
SQL;

        $this->connection->execute($sql, [
            ':revokedAtUtc' => $session->revokedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($session->revokedAtUtc()),
            ':lastUsedAtUtc' => $session->lastUsedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($session->lastUsedAtUtc()),
            ':id' => $id->toInt(),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hydrateOne(array $rows): ?GuestSession
    {
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $jwtIdRaw = AzureSqlValueMapper::nullableString($row['JwtId'] ?? null);

        return GuestSession::reconstitute(
            id: GuestSessionId::fromInt(AzureSqlValueMapper::intValue($row['GuestSessionId'] ?? null)),
            publicId: UuidPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            userId: UserId::fromInt(AzureSqlValueMapper::intValue($row['UserId'] ?? null)),
            sessionTokenHash: SessionTokenHash::fromBinary(
                AzureSqlValueMapper::binaryValue($row['SessionTokenHash'] ?? null),
            ),
            jwtId: $jwtIdRaw === null ? null : UuidPublicId::fromString($jwtIdRaw),
            issuedAtUtc: AzureSqlValueMapper::requireDateTime($row['IssuedAtUtc'] ?? null, 'IssuedAtUtc'),
            expiresAtUtc: AzureSqlValueMapper::requireDateTime($row['ExpiresAtUtc'] ?? null, 'ExpiresAtUtc'),
            revokedAtUtc: AzureSqlValueMapper::nullableDateTime($row['RevokedAtUtc'] ?? null),
            lastUsedAtUtc: AzureSqlValueMapper::nullableDateTime($row['LastUsedAtUtc'] ?? null),
            createdIpAddress: AzureSqlValueMapper::nullableString($row['CreatedIpAddress'] ?? null),
            createdUserAgent: AzureSqlValueMapper::nullableString($row['CreatedUserAgent'] ?? null),
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
        );
    }
}
