<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\TrustedDeviceRepository;
use TriCab\Core\Identity\Domain\Entity\TrustedDevice;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\TrustedDeviceId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class AzureSqlTrustedDeviceRepository implements TrustedDeviceRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
SELECT
    d.[TrustedDeviceId],
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
    d.[UserId],
    d.[CredentialHash],
    d.[PreviousCredentialHash],
    CONVERT(VARCHAR(27), d.[PreviousCredentialValidUntilUtc], 121) AS [PreviousCredentialValidUntilUtc],
    CONVERT(NVARCHAR(36), d.[InstallationId]) AS [InstallationId],
    d.[Platform],
    d.[DeviceName],
    d.[CredentialVersion],
    CONVERT(VARCHAR(27), d.[CreatedAtUtc], 121) AS [CreatedAtUtc],
    CONVERT(VARCHAR(27), d.[LastUsedAtUtc], 121) AS [LastUsedAtUtc],
    CONVERT(VARCHAR(27), d.[ExpiresAtUtc], 121) AS [ExpiresAtUtc],
    CONVERT(VARCHAR(27), d.[RevokedAtUtc], 121) AS [RevokedAtUtc],
    d.[CreatedIp],
    d.[LastUsedIp]
FROM [identity].[TrustedDevices] d
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function save(TrustedDevice $device): void
    {
        if ($device->id() === null) {
            $this->insert($device);

            return;
        }

        $this->update($device);
    }

    public function findByPublicId(UuidPublicId $publicId): ?TrustedDevice
    {
        $sql = self::SELECT_COLUMNS . ' WHERE d.[PublicId] = :publicId';

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [':publicId' => $publicId->toString()]),
        );
    }

    public function findByCredentialHash(SessionTokenHash $credentialHash): ?TrustedDevice
    {
        $sql = self::SELECT_COLUMNS . ' WHERE d.[CredentialHash] = :credentialHash';

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [
                ':credentialHash' => new SqlBinary($credentialHash->toBinary()),
            ]),
        );
    }

    public function findActiveByUserAndInstallation(UserId $userId, UuidPublicId $installationId): ?TrustedDevice
    {
        $sql = self::SELECT_COLUMNS . <<<'SQL'
 WHERE d.[UserId] = :userId
   AND d.[InstallationId] = :installationId
   AND d.[RevokedAtUtc] IS NULL
   AND d.[ExpiresAtUtc] > SYSUTCDATETIME()
SQL;

        return $this->hydrateOne(
            $this->connection->fetchAll($sql, [
                ':userId' => $userId->toInt(),
                ':installationId' => $installationId->toString(),
            ]),
        );
    }

    public function revokeByPublicIdForUser(UuidPublicId $publicId, UserId $userId): bool
    {
        $device = $this->findOwned($publicId, $userId);
        if ($device === null) {
            return false;
        }

        if ($device->isRevoked()) {
            return true;
        }

        $sql = <<<'SQL'
UPDATE [identity].[TrustedDevices]
SET
    [RevokedAtUtc] = SYSUTCDATETIME(),
    [PreviousCredentialHash] = NULL,
    [PreviousCredentialValidUntilUtc] = NULL
WHERE [PublicId] = :publicId
  AND [UserId] = :userId
  AND [RevokedAtUtc] IS NULL
SQL;

        $this->connection->execute($sql, [
            ':publicId' => $publicId->toString(),
            ':userId' => $userId->toInt(),
        ]);

        return true;
    }

    private function findOwned(UuidPublicId $publicId, UserId $userId): ?TrustedDevice
    {
        $device = $this->findByPublicId($publicId);
        if ($device === null || !$device->userId()->equals($userId)) {
            return null;
        }

        return $device;
    }

    private function insert(TrustedDevice $device): void
    {
        $sql = <<<'SQL'
INSERT INTO [identity].[TrustedDevices] (
    [PublicId],
    [UserId],
    [CredentialHash],
    [PreviousCredentialHash],
    [PreviousCredentialValidUntilUtc],
    [InstallationId],
    [Platform],
    [DeviceName],
    [CredentialVersion],
    [CreatedAtUtc],
    [LastUsedAtUtc],
    [ExpiresAtUtc],
    [RevokedAtUtc],
    [CreatedIp],
    [LastUsedIp]
)
OUTPUT INSERTED.[TrustedDeviceId]
VALUES (
    :publicId,
    :userId,
    :credentialHash,
    :previousCredentialHash,
    :previousCredentialValidUntilUtc,
    :installationId,
    :platform,
    :deviceName,
    :credentialVersion,
    :createdAtUtc,
    :lastUsedAtUtc,
    :expiresAtUtc,
    :revokedAtUtc,
    :createdIp,
    :lastUsedIp
)
SQL;

        $rows = $this->connection->fetchAll($sql, $this->params($device));
        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('TrustedDevice');
        }

        $device->assignId(
            TrustedDeviceId::fromInt(AzureSqlValueMapper::intValue($rows[0]['TrustedDeviceId'] ?? null)),
        );
    }

    private function update(TrustedDevice $device): void
    {
        $id = $device->id();
        if ($id === null) {
            throw IdentityPersistenceException::insertFailed('TrustedDevice');
        }

        $sql = <<<'SQL'
UPDATE [identity].[TrustedDevices]
SET
    [CredentialHash] = :credentialHash,
    [PreviousCredentialHash] = :previousCredentialHash,
    [PreviousCredentialValidUntilUtc] = :previousCredentialValidUntilUtc,
    [CredentialVersion] = :credentialVersion,
    [LastUsedAtUtc] = :lastUsedAtUtc,
    [RevokedAtUtc] = :revokedAtUtc,
    [LastUsedIp] = :lastUsedIp
WHERE [TrustedDeviceId] = :id
SQL;

        $this->connection->execute($sql, [
            ':credentialHash' => new SqlBinary($device->credentialHash()->toBinary()),
            ':previousCredentialHash' => $device->previousCredentialHash() === null
                ? null
                : new SqlBinary($device->previousCredentialHash()->toBinary()),
            ':previousCredentialValidUntilUtc' => $device->previousCredentialValidUntilUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($device->previousCredentialValidUntilUtc()),
            ':credentialVersion' => $device->credentialVersion(),
            ':lastUsedAtUtc' => $device->lastUsedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($device->lastUsedAtUtc()),
            ':revokedAtUtc' => $device->revokedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($device->revokedAtUtc()),
            ':lastUsedIp' => $device->lastUsedIp(),
            ':id' => $id->toInt(),
        ]);
    }

    /**
     * @return array<string, scalar|SqlBinary|null>
     */
    private function params(TrustedDevice $device): array
    {
        return [
            ':publicId' => $device->publicId()->toString(),
            ':userId' => $device->userId()->toInt(),
            ':credentialHash' => new SqlBinary($device->credentialHash()->toBinary()),
            ':previousCredentialHash' => $device->previousCredentialHash() === null
                ? null
                : new SqlBinary($device->previousCredentialHash()->toBinary()),
            ':previousCredentialValidUntilUtc' => $device->previousCredentialValidUntilUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($device->previousCredentialValidUntilUtc()),
            ':installationId' => $device->installationId()->toString(),
            ':platform' => $device->platform(),
            ':deviceName' => $device->deviceName(),
            ':credentialVersion' => $device->credentialVersion(),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($device->createdAtUtc()),
            ':lastUsedAtUtc' => $device->lastUsedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($device->lastUsedAtUtc()),
            ':expiresAtUtc' => AzureSqlValueMapper::formatDateTime($device->expiresAtUtc()),
            ':revokedAtUtc' => $device->revokedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($device->revokedAtUtc()),
            ':createdIp' => $device->createdIp(),
            ':lastUsedIp' => $device->lastUsedIp(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hydrateOne(array $rows): ?TrustedDevice
    {
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $previousRaw = $row['PreviousCredentialHash'] ?? null;
        $previousBinary = null;
        if ($previousRaw !== null && $previousRaw !== '') {
            $previousBinary = AzureSqlValueMapper::binaryValue($previousRaw);
        }

        return TrustedDevice::reconstitute(
            id: TrustedDeviceId::fromInt(AzureSqlValueMapper::intValue($row['TrustedDeviceId'] ?? null)),
            publicId: UuidPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            userId: UserId::fromInt(AzureSqlValueMapper::intValue($row['UserId'] ?? null)),
            credentialHash: SessionTokenHash::fromBinary(
                AzureSqlValueMapper::binaryValue($row['CredentialHash'] ?? null),
            ),
            previousCredentialHash: $previousBinary === null
                ? null
                : SessionTokenHash::fromBinary($previousBinary),
            previousCredentialValidUntilUtc: AzureSqlValueMapper::nullableDateTime(
                $row['PreviousCredentialValidUntilUtc'] ?? null,
            ),
            installationId: UuidPublicId::fromString(
                AzureSqlValueMapper::stringValue($row['InstallationId'] ?? null),
            ),
            platform: AzureSqlValueMapper::stringValue($row['Platform'] ?? null),
            deviceName: AzureSqlValueMapper::nullableString($row['DeviceName'] ?? null),
            credentialVersion: AzureSqlValueMapper::intValue($row['CredentialVersion'] ?? null),
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            lastUsedAtUtc: AzureSqlValueMapper::nullableDateTime($row['LastUsedAtUtc'] ?? null),
            expiresAtUtc: AzureSqlValueMapper::requireDateTime($row['ExpiresAtUtc'] ?? null, 'ExpiresAtUtc'),
            revokedAtUtc: AzureSqlValueMapper::nullableDateTime($row['RevokedAtUtc'] ?? null),
            createdIp: AzureSqlValueMapper::nullableString($row['CreatedIp'] ?? null),
            lastUsedIp: AzureSqlValueMapper::nullableString($row['LastUsedIp'] ?? null),
        );
    }
}
