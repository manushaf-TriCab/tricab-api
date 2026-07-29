<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Azure SQL implementation of the Core UserRepository contract.
 * Lives in the API layer only.
 */
final class AzureSqlUserRepository implements UserRepository
{
    private const USER_SELECT = <<<'SQL'
SELECT
    u.[UserId],
    CONVERT(NVARCHAR(36), u.[PublicId]) AS [PublicId],
    u.[EmailAddress],
    u.[DisplayName],
    s.[StatusCode],
    s.[AllowsLogin],
    r.[RoleCode],
    p.[PermissionCode]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s
    ON s.[UserStatusId] = u.[UserStatusId]
   AND s.[IsActive] = 1
LEFT JOIN [identity].[UserRoles] ur
    ON ur.[UserId] = u.[UserId]
   AND ur.[IsActive] = 1
   AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
LEFT JOIN [identity].[Roles] r
    ON r.[RoleId] = ur.[RoleId]
   AND r.[IsActive] = 1
LEFT JOIN [identity].[RolePermissions] rp
    ON rp.[RoleId] = r.[RoleId]
   AND rp.[IsActive] = 1
LEFT JOIN [identity].[Permissions] p
    ON p.[PermissionId] = rp.[PermissionId]
   AND p.[IsActive] = 1
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findById(UserId $userId): ?User
    {
        $sql = self::USER_SELECT . ' WHERE u.[UserId] = :userId';

        return $this->hydrate(
            $this->connection->fetchAll($sql, [':userId' => $userId->toInt()]),
        );
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        $sql = self::USER_SELECT . ' WHERE u.[NormalizedEmailAddress] = :email';

        return $this->hydrate(
            $this->connection->fetchAll($sql, [':email' => $emailAddress->toString()]),
        );
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        $provider = $identity->provider()->toString();
        $providerHyphen = str_replace('_', '-', $provider);

        $sql = self::USER_SELECT . <<<'SQL'

INNER JOIN [identity].[ExternalIdentities] e
    ON e.[UserId] = u.[UserId]
   AND e.[IsActive] = 1
WHERE (e.[ProviderCode] = :provider OR e.[ProviderCode] = :providerAlt)
  AND e.[TenantIdentifier] = :tenant
  AND e.[SubjectIdentifier] = :subject
SQL;

        return $this->hydrate(
            $this->connection->fetchAll($sql, [
                ':provider' => $provider,
                ':providerAlt' => $providerHyphen,
                ':tenant' => $identity->tenantId()->toString(),
                ':subject' => $identity->subjectId()->toString(),
            ]),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hydrate(array $rows): ?User
    {
        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $userId = UserId::fromInt($this->intValue($first['UserId'] ?? null));
        $publicId = UserPublicId::fromString($this->stringValue($first['PublicId'] ?? null));
        $email = EmailAddress::fromString($this->stringValue($first['EmailAddress'] ?? null));
        $displayName = $this->stringValue($first['DisplayName'] ?? null);
        if ($displayName === '') {
            $displayName = $email->toString();
        }
        $status = UserStatus::fromString($this->stringValue($first['StatusCode'] ?? null));

        $roles = [];
        $permissions = [];
        $seenRoles = [];
        $seenPermissions = [];

        foreach ($rows as $row) {
            $roleCode = $this->nullableString($row['RoleCode'] ?? null);
            if ($roleCode !== null && !isset($seenRoles[$roleCode])) {
                $roles[] = RoleCode::fromString($roleCode);
                $seenRoles[$roleCode] = true;
            }

            $permissionCode = $this->nullableString($row['PermissionCode'] ?? null);
            if ($permissionCode !== null && !isset($seenPermissions[$permissionCode])) {
                $permissions[] = PermissionCode::fromString($permissionCode);
                $seenPermissions[$permissionCode] = true;
            }
        }

        return new User(
            $userId,
            $publicId,
            $email,
            $displayName,
            $status,
            $roles,
            $permissions,
        );
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function stringValue(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = $this->stringValue($value);

        return $normalized === '' ? null : $normalized;
    }
}
