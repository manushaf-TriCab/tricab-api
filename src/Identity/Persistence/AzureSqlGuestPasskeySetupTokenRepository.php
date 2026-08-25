<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\GuestPasskeySetupTokenRepository;
use TriCab\Core\Identity\Domain\Entity\GuestPasskeySetupToken;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class AzureSqlGuestPasskeySetupTokenRepository implements GuestPasskeySetupTokenRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
SELECT
    t.[GuestPasskeySetupTokenId],
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId],
    t.[UserId],
    t.[TokenHash],
    CONVERT(VARCHAR(27), t.[ExpiresAtUtc], 121) AS [ExpiresAtUtc],
    CONVERT(VARCHAR(27), t.[ConsumedAtUtc], 121) AS [ConsumedAtUtc],
    CONVERT(VARCHAR(27), t.[CreatedAtUtc], 121) AS [CreatedAtUtc],
    t.[RequestedIpHash]
FROM [identity].[GuestPasskeySetupTokens] t
SQL;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function save(GuestPasskeySetupToken $token): void
    {
        if ($token->id() === null) {
            $this->insert($token);

            return;
        }

        $this->connection->execute(
            <<<'SQL'
UPDATE [identity].[GuestPasskeySetupTokens]
SET [ConsumedAtUtc] = :consumedAtUtc
WHERE [GuestPasskeySetupTokenId] = :id
SQL,
            [
                ':consumedAtUtc' => $token->consumedAtUtc() === null
                    ? null
                    : AzureSqlValueMapper::formatDateTime($token->consumedAtUtc()),
                ':id' => $token->id(),
            ],
        );
    }

    public function findByTokenHash(string $tokenHash): ?GuestPasskeySetupToken
    {
        $sql = self::SELECT_COLUMNS . ' WHERE t.[TokenHash] = :tokenHash';

        return $this->hydrateOne($this->connection->fetchAll($sql, [':tokenHash' => new SqlBinary($tokenHash)]));
    }

    public function findByPublicId(UuidPublicId $publicId): ?GuestPasskeySetupToken
    {
        $sql = self::SELECT_COLUMNS . ' WHERE t.[PublicId] = :publicId';

        return $this->hydrateOne($this->connection->fetchAll($sql, [':publicId' => $publicId->toString()]));
    }

    private function insert(GuestPasskeySetupToken $token): void
    {
        $sql = <<<'SQL'
INSERT INTO [identity].[GuestPasskeySetupTokens] (
    [PublicId], [UserId], [TokenHash], [ExpiresAtUtc], [ConsumedAtUtc], [CreatedAtUtc], [RequestedIpHash]
)
OUTPUT INSERTED.[GuestPasskeySetupTokenId]
VALUES (:publicId, :userId, :tokenHash, :expiresAtUtc, :consumedAtUtc, :createdAtUtc, :requestedIpHash)
SQL;
        $rows = $this->connection->fetchAll($sql, [
            ':publicId' => $token->publicId()->toString(),
            ':userId' => $token->userId()->toInt(),
            ':tokenHash' => new SqlBinary($token->tokenHash()),
            ':expiresAtUtc' => AzureSqlValueMapper::formatDateTime($token->expiresAtUtc()),
            ':consumedAtUtc' => $token->consumedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($token->consumedAtUtc()),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($token->createdAtUtc()),
            ':requestedIpHash' => $token->requestedIpHash() === null ? null : new SqlBinary($token->requestedIpHash()),
        ]);
        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('GuestPasskeySetupToken');
        }
        $token->assignId(AzureSqlValueMapper::intValue($rows[0]['GuestPasskeySetupTokenId'] ?? null));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hydrateOne(array $rows): ?GuestPasskeySetupToken
    {
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $ip = $row['RequestedIpHash'] ?? null;

        return new GuestPasskeySetupToken(
            id: AzureSqlValueMapper::intValue($row['GuestPasskeySetupTokenId'] ?? null),
            publicId: UuidPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            userId: UserId::fromInt(AzureSqlValueMapper::intValue($row['UserId'] ?? null)),
            tokenHash: AzureSqlValueMapper::binaryValue($row['TokenHash'] ?? null),
            expiresAtUtc: AzureSqlValueMapper::requireDateTime($row['ExpiresAtUtc'] ?? null, 'ExpiresAtUtc'),
            consumedAtUtc: AzureSqlValueMapper::nullableDateTime($row['ConsumedAtUtc'] ?? null),
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            requestedIpHash: $ip === null ? null : AzureSqlValueMapper::binaryValue($ip),
        );
    }
}
