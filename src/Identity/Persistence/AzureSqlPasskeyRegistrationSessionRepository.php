<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\PasskeyRegistrationSessionRepository;
use TriCab\Core\Identity\Domain\Entity\PasskeyRegistrationSession;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class AzureSqlPasskeyRegistrationSessionRepository implements PasskeyRegistrationSessionRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
SELECT
    s.[PasskeyRegistrationSessionId],
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [PublicId],
    s.[UserId],
    CONVERT(NVARCHAR(36), s.[SetupTokenPublicId]) AS [SetupTokenPublicId],
    s.[SessionTokenHash],
    CONVERT(VARCHAR(27), s.[ExpiresAtUtc], 121) AS [ExpiresAtUtc],
    CONVERT(VARCHAR(27), s.[ConsumedAtUtc], 121) AS [ConsumedAtUtc],
    CONVERT(VARCHAR(27), s.[CreatedAtUtc], 121) AS [CreatedAtUtc]
FROM [identity].[PasskeyRegistrationSessions] s
SQL;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function save(PasskeyRegistrationSession $session): void
    {
        if ($session->id() === null) {
            $this->insert($session);

            return;
        }

        $this->connection->execute(
            <<<'SQL'
UPDATE [identity].[PasskeyRegistrationSessions]
SET [ConsumedAtUtc] = :consumedAtUtc
WHERE [PasskeyRegistrationSessionId] = :id
SQL,
            [
                ':consumedAtUtc' => $session->consumedAtUtc() === null
                    ? null
                    : AzureSqlValueMapper::formatDateTime($session->consumedAtUtc()),
                ':id' => $session->id(),
            ],
        );
    }

    public function findBySessionTokenHash(string $sessionTokenHash): ?PasskeyRegistrationSession
    {
        $sql = self::SELECT_COLUMNS . ' WHERE s.[SessionTokenHash] = :sessionTokenHash';
        $rows = $this->connection->fetchAll($sql, [':sessionTokenHash' => new SqlBinary($sessionTokenHash)]);
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return new PasskeyRegistrationSession(
            id: AzureSqlValueMapper::intValue($row['PasskeyRegistrationSessionId'] ?? null),
            publicId: UuidPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            userId: UserId::fromInt(AzureSqlValueMapper::intValue($row['UserId'] ?? null)),
            setupTokenPublicId: UuidPublicId::fromString(AzureSqlValueMapper::stringValue($row['SetupTokenPublicId'] ?? null)),
            sessionTokenHash: AzureSqlValueMapper::binaryValue($row['SessionTokenHash'] ?? null),
            expiresAtUtc: AzureSqlValueMapper::requireDateTime($row['ExpiresAtUtc'] ?? null, 'ExpiresAtUtc'),
            consumedAtUtc: AzureSqlValueMapper::nullableDateTime($row['ConsumedAtUtc'] ?? null),
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
        );
    }

    private function insert(PasskeyRegistrationSession $session): void
    {
        $sql = <<<'SQL'
INSERT INTO [identity].[PasskeyRegistrationSessions] (
    [PublicId], [UserId], [SetupTokenPublicId], [SessionTokenHash], [ExpiresAtUtc], [ConsumedAtUtc], [CreatedAtUtc]
)
OUTPUT INSERTED.[PasskeyRegistrationSessionId]
VALUES (:publicId, :userId, :setupTokenPublicId, :sessionTokenHash, :expiresAtUtc, :consumedAtUtc, :createdAtUtc)
SQL;
        $rows = $this->connection->fetchAll($sql, [
            ':publicId' => $session->publicId()->toString(),
            ':userId' => $session->userId()->toInt(),
            ':setupTokenPublicId' => $session->setupTokenPublicId()->toString(),
            ':sessionTokenHash' => new SqlBinary($session->sessionTokenHash()),
            ':expiresAtUtc' => AzureSqlValueMapper::formatDateTime($session->expiresAtUtc()),
            ':consumedAtUtc' => $session->consumedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($session->consumedAtUtc()),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($session->createdAtUtc()),
        ]);
        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('PasskeyRegistrationSession');
        }
        $session->assignId(AzureSqlValueMapper::intValue($rows[0]['PasskeyRegistrationSessionId'] ?? null));
    }
}
