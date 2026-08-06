<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\EmailOtpChallengeRepository;
use TriCab\Core\Identity\Domain\Entity\EmailOtpChallenge;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\EmailOtpChallengeId;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

/**
 * Azure SQL persistence for email OTP challenges.
 */
final class AzureSqlEmailOtpChallengeRepository implements EmailOtpChallengeRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
SELECT
    c.[EmailOtpChallengeId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId],
    c.[EmailAddress],
    c.[OtpHash],
    c.[PurposeCode],
    CONVERT(VARCHAR(27), c.[ExpiresAtUtc], 121) AS [ExpiresAtUtc],
    c.[AttemptCount],
    c.[MaximumAttempts],
    CONVERT(VARCHAR(27), c.[ConsumedAtUtc], 121) AS [ConsumedAtUtc],
    CONVERT(VARCHAR(27), c.[InvalidatedAtUtc], 121) AS [InvalidatedAtUtc],
    c.[RequestedIpAddress],
    c.[RequestedUserAgent],
    CONVERT(VARCHAR(27), c.[CreatedAtUtc], 121) AS [CreatedAtUtc]
FROM [identity].[EmailOtpChallenges] c
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function save(EmailOtpChallenge $challenge): void
    {
        if ($challenge->id() === null) {
            $this->insert($challenge);

            return;
        }

        $this->update($challenge);
    }

    public function findById(EmailOtpChallengeId $id): ?EmailOtpChallenge
    {
        $sql = self::SELECT_COLUMNS . ' WHERE c.[EmailOtpChallengeId] = :id';

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [':id' => $id->toInt()]),
        );
    }

    public function findByPublicId(UuidPublicId $publicId): ?EmailOtpChallenge
    {
        $sql = self::SELECT_COLUMNS . ' WHERE c.[PublicId] = :publicId';

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [':publicId' => $publicId->toString()]),
        );
    }

    public function findLatestActiveByEmailAndPurpose(
        EmailAddress $emailAddress,
        OtpPurpose $purpose,
    ): ?EmailOtpChallenge {
        $sql = self::SELECT_COLUMNS . <<<'SQL'

WHERE c.[EmailAddress] = :email
  AND c.[PurposeCode] = :purpose
  AND c.[ConsumedAtUtc] IS NULL
  AND c.[InvalidatedAtUtc] IS NULL
  AND c.[ExpiresAtUtc] > SYSUTCDATETIME()
ORDER BY c.[CreatedAtUtc] DESC
OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY
SQL;

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [
                ':email' => $emailAddress->toString(),
                ':purpose' => $purpose->toString(),
            ]),
        );
    }

    public function invalidateActiveChallenges(
        EmailAddress $emailAddress,
        OtpPurpose $purpose,
    ): void {
        $sql = <<<'SQL'
UPDATE [identity].[EmailOtpChallenges]
SET [InvalidatedAtUtc] = SYSUTCDATETIME()
WHERE [EmailAddress] = :email
  AND [PurposeCode] = :purpose
  AND [ConsumedAtUtc] IS NULL
  AND [InvalidatedAtUtc] IS NULL
  AND [ExpiresAtUtc] > SYSUTCDATETIME()
SQL;

        $this->connection->execute($sql, [
            ':email' => $emailAddress->toString(),
            ':purpose' => $purpose->toString(),
        ]);
    }

    private function insert(EmailOtpChallenge $challenge): void
    {
        $sql = <<<'SQL'
INSERT INTO [identity].[EmailOtpChallenges] (
    [PublicId],
    [EmailAddress],
    [OtpHash],
    [PurposeCode],
    [ExpiresAtUtc],
    [AttemptCount],
    [MaximumAttempts],
    [ConsumedAtUtc],
    [InvalidatedAtUtc],
    [RequestedIpAddress],
    [RequestedUserAgent],
    [CreatedAtUtc]
)
OUTPUT
    INSERTED.[EmailOtpChallengeId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :publicId,
    :email,
    :otpHash,
    :purpose,
    :expiresAtUtc,
    :attemptCount,
    :maximumAttempts,
    :consumedAtUtc,
    :invalidatedAtUtc,
    :requestedIpAddress,
    :requestedUserAgent,
    :createdAtUtc
)
SQL;

        $rows = $this->connection->fetchAll($sql, [
            ':publicId' => $challenge->publicId()->toString(),
            ':email' => $challenge->emailAddress()->toString(),
            ':otpHash' => $challenge->otpHash(),
            ':purpose' => $challenge->purpose()->toString(),
            ':expiresAtUtc' => AzureSqlValueMapper::formatDateTime($challenge->expiresAtUtc()),
            ':attemptCount' => $challenge->attemptCount(),
            ':maximumAttempts' => $challenge->maximumAttempts(),
            ':consumedAtUtc' => $challenge->consumedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($challenge->consumedAtUtc()),
            ':invalidatedAtUtc' => $challenge->invalidatedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($challenge->invalidatedAtUtc()),
            ':requestedIpAddress' => $challenge->requestedIpAddress(),
            ':requestedUserAgent' => $challenge->requestedUserAgent(),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($challenge->createdAtUtc()),
        ]);

        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('EmailOtpChallenge');
        }

        $challenge->assignId(
            EmailOtpChallengeId::fromInt(AzureSqlValueMapper::intValue($rows[0]['EmailOtpChallengeId'] ?? null)),
        );
    }

    private function update(EmailOtpChallenge $challenge): void
    {
        $id = $challenge->id();
        if ($id === null) {
            throw IdentityPersistenceException::insertFailed('EmailOtpChallenge');
        }

        $sql = <<<'SQL'
UPDATE [identity].[EmailOtpChallenges]
SET
    [AttemptCount] = :attemptCount,
    [ConsumedAtUtc] = :consumedAtUtc,
    [InvalidatedAtUtc] = :invalidatedAtUtc
WHERE [EmailOtpChallengeId] = :id
SQL;

        $this->connection->execute($sql, [
            ':attemptCount' => $challenge->attemptCount(),
            ':consumedAtUtc' => $challenge->consumedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($challenge->consumedAtUtc()),
            ':invalidatedAtUtc' => $challenge->invalidatedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($challenge->invalidatedAtUtc()),
            ':id' => $id->toInt(),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hydrateOne(array $rows): ?EmailOtpChallenge
    {
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return EmailOtpChallenge::reconstitute(
            id: EmailOtpChallengeId::fromInt(AzureSqlValueMapper::intValue($row['EmailOtpChallengeId'] ?? null)),
            publicId: UuidPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            emailAddress: EmailAddress::fromString(AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? null)),
            otpHash: AzureSqlValueMapper::stringValue($row['OtpHash'] ?? null),
            purpose: OtpPurpose::fromString(AzureSqlValueMapper::stringValue($row['PurposeCode'] ?? null)),
            expiresAtUtc: AzureSqlValueMapper::requireDateTime($row['ExpiresAtUtc'] ?? null, 'ExpiresAtUtc'),
            attemptCount: AzureSqlValueMapper::intValue($row['AttemptCount'] ?? null),
            maximumAttempts: AzureSqlValueMapper::intValue($row['MaximumAttempts'] ?? null),
            consumedAtUtc: AzureSqlValueMapper::nullableDateTime($row['ConsumedAtUtc'] ?? null),
            invalidatedAtUtc: AzureSqlValueMapper::nullableDateTime($row['InvalidatedAtUtc'] ?? null),
            requestedIpAddress: AzureSqlValueMapper::nullableString($row['RequestedIpAddress'] ?? null),
            requestedUserAgent: AzureSqlValueMapper::nullableString($row['RequestedUserAgent'] ?? null),
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
        );
    }
}
