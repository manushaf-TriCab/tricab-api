<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use Throwable;
use TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics;
use TriCab\Api\Infrastructure\Database\SqlBinary;
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
        $binary = new SqlBinary($sessionTokenHash->toBinary());

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [
                ':sessionTokenHash' => $binary,
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

        $tokenHashBinary = new SqlBinary($session->sessionTokenHash()->toBinary());
        $params = [
            ':publicId' => $session->publicId()->toString(),
            ':userId' => $session->userId()->toInt(),
            ':sessionTokenHash' => $tokenHashBinary,
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
        ];

        try {
            $rows = $this->connection->fetchAll($sql, $params);
        } catch (Throwable $exception) {
            DatabaseAuthDiagnostics::logThrowable('guest_session_insert_failed', $exception, [
                'insertStage' => 'GuestSessions.insert',
                'parameterNames' => array_keys($params),
                'parameterTypes' => $this->describeParamTypes($params),
                'parameterLengths' => $this->describeParamLengths($params),
                'sessionTokenHashType' => 'binary',
                'sessionTokenHashByteLength' => $tokenHashBinary->length(),
                'sessionTokenHashIsBinary' => true,
                'userId' => $session->userId()->toInt(),
                'publicIdLength' => strlen($session->publicId()->toString()),
                'jwtIdPresent' => $session->jwtId() !== null,
                'issuedAtUtc' => $params[':issuedAtUtc'],
                'expiresAtUtc' => $params[':expiresAtUtc'],
                'createdIpAddressLength' => is_string($params[':createdIpAddress'])
                    ? strlen($params[':createdIpAddress'])
                    : null,
                'createdUserAgentLength' => is_string($params[':createdUserAgent'])
                    ? strlen($params[':createdUserAgent'])
                    : null,
            ]);

            throw $exception;
        }

        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('GuestSession');
        }

        $session->assignId(
            GuestSessionId::fromInt(AzureSqlValueMapper::intValue($rows[0]['GuestSessionId'] ?? null)),
        );
    }

    /**
     * @param array<string, scalar|SqlBinary|null> $params
     * @return array<string, string>
     */
    private function describeParamTypes(array $params): array
    {
        $types = [];
        foreach ($params as $name => $value) {
            if ($value instanceof SqlBinary) {
                $types[$name] = 'SqlBinary';
                continue;
            }

            $types[$name] = $value === null ? 'null' : get_debug_type($value);
        }

        return $types;
    }

    /**
     * @param array<string, scalar|SqlBinary|null> $params
     * @return array<string, int|null>
     */
    private function describeParamLengths(array $params): array
    {
        $lengths = [];
        foreach ($params as $name => $value) {
            if ($value instanceof SqlBinary) {
                $lengths[$name] = $value->length();
                continue;
            }

            if (is_string($value)) {
                $lengths[$name] = strlen($value);
                continue;
            }

            $lengths[$name] = null;
        }

        return $lengths;
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
