<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\UserPasskeyRepository;
use TriCab\Core\Identity\Domain\Entity\UserPasskey;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Identity\Domain\ValueObject\WebAuthnUserHandle;

final class AzureSqlUserPasskeyRepository implements UserPasskeyRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
SELECT
    p.[UserPasskeyId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[UserId],
    p.[CredentialId],
    p.[PublicKey],
    p.[SignCount],
    p.[Transports],
    p.[Aaguid],
    p.[DeviceLabel],
    CONVERT(VARCHAR(27), p.[CreatedAtUtc], 121) AS [CreatedAtUtc],
    CONVERT(VARCHAR(27), p.[LastUsedAtUtc], 121) AS [LastUsedAtUtc],
    p.[IsActive]
FROM [identity].[UserPasskeys] p
SQL;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function save(UserPasskey $passkey): void
    {
        if ($passkey->id() === null) {
            $this->insert($passkey);

            return;
        }

        $this->update($passkey);
    }

    public function findByCredentialId(string $credentialId): ?UserPasskey
    {
        $sql = self::SELECT_COLUMNS . ' WHERE p.[CredentialId] = :credentialId';

        return $this->hydrateOne($this->connection->fetchAll($sql, [':credentialId' => $credentialId]));
    }

    public function findByPublicId(UuidPublicId $publicId): ?UserPasskey
    {
        $sql = self::SELECT_COLUMNS . ' WHERE p.[PublicId] = :publicId';

        return $this->hydrateOne($this->connection->fetchAll($sql, [':publicId' => $publicId->toString()]));
    }

    public function listActiveByUserId(UserId $userId): array
    {
        $sql = self::SELECT_COLUMNS . ' WHERE p.[UserId] = :userId AND p.[IsActive] = 1 ORDER BY p.[CreatedAtUtc] ASC';
        $out = [];
        foreach ($this->connection->fetchAll($sql, [':userId' => $userId->toInt()]) as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function findHandleByUserId(UserId $userId): ?WebAuthnUserHandle
    {
        $rows = $this->connection->fetchAll(
            'SELECT [UserHandle] FROM [identity].[UserWebAuthnHandles] WHERE [UserId] = :userId',
            [':userId' => $userId->toInt()],
        );
        if ($rows === []) {
            return null;
        }

        return WebAuthnUserHandle::fromBinary(AzureSqlValueMapper::binaryValue($rows[0]['UserHandle'] ?? null));
    }

    public function saveHandle(UserId $userId, WebAuthnUserHandle $handle): void
    {
        $sql = <<<'SQL'
IF NOT EXISTS (SELECT 1 FROM [identity].[UserWebAuthnHandles] WHERE [UserId] = :userId)
    INSERT INTO [identity].[UserWebAuthnHandles] ([UserId], [UserHandle])
    VALUES (:userIdInsert, :userHandle);
SQL;
        $this->connection->execute($sql, [
            ':userId' => $userId->toInt(),
            ':userIdInsert' => $userId->toInt(),
            ':userHandle' => new SqlBinary($handle->toBinary()),
        ]);
    }

    public function findUserIdByHandle(WebAuthnUserHandle $handle): ?UserId
    {
        $rows = $this->connection->fetchAll(
            'SELECT [UserId] FROM [identity].[UserWebAuthnHandles] WHERE [UserHandle] = :userHandle',
            [':userHandle' => new SqlBinary($handle->toBinary())],
        );
        if ($rows === []) {
            return null;
        }

        return UserId::fromInt(AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? null));
    }

    private function insert(UserPasskey $passkey): void
    {
        $sql = <<<'SQL'
INSERT INTO [identity].[UserPasskeys] (
    [PublicId], [UserId], [CredentialId], [PublicKey], [SignCount], [Transports],
    [Aaguid], [DeviceLabel], [CreatedAtUtc], [LastUsedAtUtc], [IsActive]
)
OUTPUT INSERTED.[UserPasskeyId]
VALUES (
    :publicId, :userId, :credentialId, :publicKey, :signCount, :transports,
    :aaguid, :deviceLabel, :createdAtUtc, :lastUsedAtUtc, :isActive
)
SQL;
        $rows = $this->connection->fetchAll($sql, $this->bind($passkey));
        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('UserPasskey');
        }
        $passkey->assignId(AzureSqlValueMapper::intValue($rows[0]['UserPasskeyId'] ?? null));
    }

    private function update(UserPasskey $passkey): void
    {
        $id = $passkey->id();
        if ($id === null) {
            throw IdentityPersistenceException::insertFailed('UserPasskey');
        }
        $this->connection->execute(
            <<<'SQL'
UPDATE [identity].[UserPasskeys]
SET [SignCount] = :signCount,
    [DeviceLabel] = :deviceLabel,
    [LastUsedAtUtc] = :lastUsedAtUtc,
    [IsActive] = :isActive
WHERE [UserPasskeyId] = :id
SQL,
            [
                ':signCount' => $passkey->signCount(),
                ':deviceLabel' => $passkey->deviceLabel(),
                ':lastUsedAtUtc' => $passkey->lastUsedAtUtc() === null
                    ? null
                    : AzureSqlValueMapper::formatDateTime($passkey->lastUsedAtUtc()),
                ':isActive' => $passkey->isActive() ? 1 : 0,
                ':id' => $id,
            ],
        );
    }

    /**
     * @return array<string, bool|float|int|string|SqlBinary|null>
     */
    private function bind(UserPasskey $passkey): array
    {
        return [
            ':publicId' => $passkey->publicId()->toString(),
            ':userId' => $passkey->userId()->toInt(),
            ':credentialId' => $passkey->credentialId(),
            ':publicKey' => $passkey->publicKey(),
            ':signCount' => $passkey->signCount(),
            ':transports' => $passkey->transports() === [] ? null : implode(',', $passkey->transports()),
            ':aaguid' => $passkey->aaguid(),
            ':deviceLabel' => $passkey->deviceLabel(),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($passkey->createdAtUtc()),
            ':lastUsedAtUtc' => $passkey->lastUsedAtUtc() === null
                ? null
                : AzureSqlValueMapper::formatDateTime($passkey->lastUsedAtUtc()),
            ':isActive' => $passkey->isActive() ? 1 : 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hydrateOne(array $rows): ?UserPasskey
    {
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): UserPasskey
    {
        $transportsRaw = AzureSqlValueMapper::nullableString($row['Transports'] ?? null);
        $transports = $transportsRaw === null || $transportsRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $transportsRaw)), static fn (string $v): bool => $v !== ''));

        return new UserPasskey(
            id: AzureSqlValueMapper::intValue($row['UserPasskeyId'] ?? null),
            publicId: UuidPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            userId: UserId::fromInt(AzureSqlValueMapper::intValue($row['UserId'] ?? null)),
            credentialId: AzureSqlValueMapper::stringValue($row['CredentialId'] ?? null),
            publicKey: AzureSqlValueMapper::stringValue($row['PublicKey'] ?? null),
            signCount: AzureSqlValueMapper::intValue($row['SignCount'] ?? null),
            transports: $transports,
            aaguid: AzureSqlValueMapper::nullableString($row['Aaguid'] ?? null),
            deviceLabel: AzureSqlValueMapper::stringValue($row['DeviceLabel'] ?? null),
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            lastUsedAtUtc: AzureSqlValueMapper::nullableDateTime($row['LastUsedAtUtc'] ?? null),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? null),
        );
    }
}
