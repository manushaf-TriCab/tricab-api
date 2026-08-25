<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\PasskeyChallengeRepository;
use TriCab\Core\Identity\Domain\Entity\PasskeyChallenge;
use TriCab\Core\Identity\Domain\ValueObject\PasskeyChallengePurpose;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class AzureSqlPasskeyChallengeRepository implements PasskeyChallengeRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
SELECT
    c.[PasskeyChallengeId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId],
    c.[UserId],
    CONVERT(NVARCHAR(36), c.[RegistrationSessionPublicId]) AS [RegistrationSessionPublicId],
    c.[PurposeCode],
    c.[Challenge],
    CONVERT(VARCHAR(27), c.[ExpiresAtUtc], 121) AS [ExpiresAtUtc],
    CONVERT(VARCHAR(27), c.[ConsumedAtUtc], 121) AS [ConsumedAtUtc],
    CONVERT(VARCHAR(27), c.[CreatedAtUtc], 121) AS [CreatedAtUtc]
FROM [identity].[PasskeyChallenges] c
SQL;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function save(PasskeyChallenge $challenge): void
    {
        if ($challenge->id() === null) {
            $this->insert($challenge);

            return;
        }

        $this->connection->execute(
            <<<'SQL'
UPDATE [identity].[PasskeyChallenges]
SET [ConsumedAtUtc] = :consumedAtUtc
WHERE [PasskeyChallengeId] = :id
SQL,
            [
                ':consumedAtUtc' => $challenge->consumedAtUtc() === null
                    ? null
                    : AzureSqlValueMapper::formatDateTime($challenge->consumedAtUtc()),
                ':id' => $challenge->id(),
            ],
        );
    }

    public function findByPublicId(UuidPublicId $publicId): ?PasskeyChallenge
    {
        $sql = self::SELECT_COLUMNS . ' WHERE c.[PublicId] = :publicId';
        $rows = $this->connection->fetchAll($sql, [':publicId' => $publicId->toString()]);
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $userId = AzureSqlValueMapper::nullableInt($row['UserId'] ?? null);
        $session = AzureSqlValueMapper::nullableString($row['RegistrationSessionPublicId'] ?? null);

        return new PasskeyChallenge(
            id: AzureSqlValueMapper::intValue($row['PasskeyChallengeId'] ?? null),
            publicId: UuidPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            userId: $userId === null ? null : UserId::fromInt($userId),
            registrationSessionPublicId: $session === null ? null : UuidPublicId::fromString($session),
            purpose: PasskeyChallengePurpose::fromString(AzureSqlValueMapper::stringValue($row['PurposeCode'] ?? null)),
            challengeBinary: AzureSqlValueMapper::rawBinary($row['Challenge'] ?? null),
            expiresAtUtc: AzureSqlValueMapper::requireDateTime($row['ExpiresAtUtc'] ?? null, 'ExpiresAtUtc'),
            consumedAtUtc: AzureSqlValueMapper::nullableDateTime($row['ConsumedAtUtc'] ?? null),
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
        );
    }

    private function insert(PasskeyChallenge $challenge): void
    {
        $sql = <<<'SQL'
INSERT INTO [identity].[PasskeyChallenges] (
    [PublicId], [UserId], [RegistrationSessionPublicId], [PurposeCode], [Challenge],
    [ExpiresAtUtc], [ConsumedAtUtc], [CreatedAtUtc]
)
OUTPUT INSERTED.[PasskeyChallengeId]
VALUES (
    :publicId, :userId, :registrationSessionPublicId, :purpose, :challenge,
    :expiresAtUtc, :consumedAtUtc, :createdAtUtc
)
SQL;
        $session = $challenge->registrationSessionPublicId();
        $rows = $this->connection->fetchAll($sql, [
            ':publicId' => $challenge->publicId()->toString(),
            ':userId' => $challenge->userId()?->toInt(),
            ':registrationSessionPublicId' => $session?->toString(),
            ':purpose' => $challenge->purpose()->toString(),
            ':challenge' => new SqlBinary($challenge->challengeBinary()),
            ':expiresAtUtc' => AzureSqlValueMapper::formatDateTime($challenge->expiresAtUtc()),
            ':consumedAtUtc' => $challenge->consumedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($challenge->consumedAtUtc()),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($challenge->createdAtUtc()),
        ]);
        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('PasskeyChallenge');
        }
        $challenge->assignId(AzureSqlValueMapper::intValue($rows[0]['PasskeyChallengeId'] ?? null));
    }
}
