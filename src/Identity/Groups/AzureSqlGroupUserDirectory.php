<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

use InvalidArgumentException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/** Platform users, as the groups need them, on Azure SQL. */
final class AzureSqlGroupUserDirectory implements GroupUserDirectory
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function findIdByPublicId(string $publicId): ?int
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($publicId)) !== 1) {
            return null;
        }
        $rows = $this->connection->fetchAll('SELECT [UserId] FROM [identity].[Users] WHERE [PublicId] = :id', [':id' => trim($publicId)]);

        return $rows === [] ? null : AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? 0);
    }

    public function findIdByEntraObjectId(string $objectId): ?int
    {
        $rows = $this->connection->fetchAll(
            "SELECT TOP 1 [UserId] FROM [identity].[ExternalIdentities]
             WHERE [IsActive] = 1 AND [ProviderCode] IN (N'microsoft_entra', N'microsoft-entra') AND [SubjectIdentifier] = :subject
             ORDER BY [ExternalIdentityId]",
            [':subject' => $objectId],
        );

        return $rows === [] ? null : AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? 0);
    }

    public function findIdByEmail(string $email): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :email',
            [':email' => strtolower($email)],
        );
        if ($rows !== []) {
            return AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? 0);
        }

        // Every address a user has proven control of (Phase P), when that table is on this database.
        $rows = $this->connection->fetchAll(
            "IF OBJECT_ID(N'[identity].[UserEmailAddresses]', N'U') IS NOT NULL
                SELECT TOP 1 [UserId] FROM [identity].[UserEmailAddresses] WHERE [IsActive] = 1 AND [NormalizedEmailAddress] = :email ORDER BY [UserEmailAddressId]
             ELSE
                SELECT TOP 0 CAST(NULL AS BIGINT) AS [UserId]",
            [':email' => strtolower($email)],
        );

        return $rows === [] ? null : AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? 0);
    }

    public function createPending(string $email, string $displayName): int
    {
        try {
            $address = EmailAddress::fromString($email)->toString();
        } catch (InvalidIdentityValue) {
            throw new InvalidArgumentException('Not a valid email address.');
        }

        $rows = $this->connection->fetchAll(
            "INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
             OUTPUT INSERTED.[UserId]
             SELECT :email, :normalized, :name, [UserStatusId] FROM [identity].[UserStatuses] WHERE [StatusCode] = N'pending'",
            [':email' => $address, ':normalized' => $address, ':name' => $displayName],
        );
        if ($rows === []) {
            throw new \RuntimeException('The pending user was not created (no pending status row).');
        }

        return AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? 0);
    }

    public function describe(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $userIds));
        $rows = $this->connection->fetchAll(
            "SELECT u.[UserId], CONVERT(NVARCHAR(36), u.[PublicId]) AS [PublicId], u.[DisplayName], u.[EmailAddress], s.[StatusCode]
             FROM [identity].[Users] u
             JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
             WHERE u.[UserId] IN ({$in})",
        );
        $found = [];
        foreach ($rows as $row) {
            $email = AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? '');
            $name = AzureSqlValueMapper::stringValue($row['DisplayName'] ?? '');
            $found[AzureSqlValueMapper::intValue($row['UserId'] ?? 0)] = [
                'publicId' => strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
                'displayName' => $name !== '' ? $name : $email,
                'emailAddress' => $email,
                'status' => strtolower(AzureSqlValueMapper::stringValue($row['StatusCode'] ?? '')),
            ];
        }

        return $found;
    }
}
