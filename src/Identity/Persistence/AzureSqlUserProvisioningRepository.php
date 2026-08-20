<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use Throwable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Azure SQL guest user provisioning.
 * Reads reuse AzureSqlUserRepository; writes run in a single transaction.
 */
final class AzureSqlUserProvisioningRepository implements UserProvisioningRepository
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SqlConnection $connection,
        private readonly UuidV4Generator $uuidGenerator = new UuidV4Generator(),
    ) {
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        return $this->users->findByEmail($emailAddress);
    }

    /**
     * @return list<User>
     */
    public function findAllByEmail(EmailAddress $emailAddress): array
    {
        $user = $this->users->findByEmail($emailAddress);

        return $user !== null ? [$user] : [];
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        return $this->users->findByExternalIdentity($identity);
    }

    /**
     * @param list<RoleCode> $roles
     * @param list<PermissionCode> $permissions
     */
    public function register(
        EmailAddress $emailAddress,
        string $displayName,
        UserStatus $status,
        array $roles,
        array $permissions,
        ExternalIdentity $identity,
    ): User {
        // Permissions (e.g. ACCOUNT.LOGIN) are granted through RolePermissions for assigned roles.
        if ($permissions !== [] && $roles === []) {
            throw IdentityPersistenceException::roleNotFound('(permissions without roles)');
        }

        $this->connection->beginTransaction();

        try {
            $statusId = $this->resolveStatusId($status);
            $publicId = $this->uuidGenerator->generate()->toString();

            $userId = $this->insertUser(
                publicId: $publicId,
                emailAddress: $emailAddress,
                displayName: $displayName,
                statusId: $statusId,
            );

            $this->insertExternalIdentity($userId, $emailAddress, $identity);

            foreach ($roles as $role) {
                $this->assignRole($userId, $role);
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            error_log('Guest user provisioning failed: ' . $exception->getMessage());

            throw $exception;
        }

        $provisioned = $this->users->findByEmail($emailAddress);
        if ($provisioned === null) {
            throw IdentityPersistenceException::insertFailed('User');
        }

        return $provisioned;
    }

    public function linkExternalIdentity(User $user, ExternalIdentity $identity): void
    {
        $this->connection->beginTransaction();

        try {
            $this->insertExternalIdentity(
                $user->id()->toInt(),
                $user->emailAddress(),
                $identity,
            );
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            error_log('Guest external identity link failed: ' . $exception->getMessage());

            throw $exception;
        }
    }

    public function findActiveProviderSubjectForUser(UserId $userId, ExternalProvider $provider): ?ExternalSubjectId
    {
        $providerAlt = str_replace('_', '-', $provider->toString());
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1 [SubjectIdentifier]
FROM [identity].[ExternalIdentities]
WHERE [UserId] = :userId
  AND [IsActive] = 1
  AND ([ProviderCode] = :provider OR [ProviderCode] = :providerAlt)
SQL,
            [
                ':userId' => $userId->toInt(),
                ':provider' => $provider->toString(),
                ':providerAlt' => $providerAlt,
            ],
        );

        if ($rows === []) {
            return null;
        }

        $subject = trim((string) ($rows[0]['SubjectIdentifier'] ?? ''));

        return $subject !== '' ? ExternalSubjectId::fromString($subject) : null;
    }

    private function resolveStatusId(UserStatus $status): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [UserStatusId]
FROM [identity].[UserStatuses]
WHERE [StatusCode] = :statusCode
  AND [IsActive] = 1
SQL,
            [':statusCode' => $status->toString()],
        );

        if ($rows === []) {
            throw IdentityPersistenceException::statusNotFound($status->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['UserStatusId'] ?? null);
    }

    private function insertUser(
        string $publicId,
        EmailAddress $emailAddress,
        string $displayName,
        int $statusId,
    ): int {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [identity].[Users] (
    [PublicId],
    [EmailAddress],
    [NormalizedEmailAddress],
    [DisplayName],
    [UserStatusId]
)
OUTPUT INSERTED.[UserId]
VALUES (
    :publicId,
    :email,
    :normalizedEmail,
    :displayName,
    :statusId
)
SQL,
            [
                ':publicId' => $publicId,
                ':email' => $emailAddress->toString(),
                ':normalizedEmail' => $emailAddress->toString(),
                ':displayName' => $displayName,
                ':statusId' => $statusId,
            ],
        );

        if ($rows === []) {
            throw IdentityPersistenceException::insertFailed('User');
        }

        return AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? null);
    }

    private function insertExternalIdentity(
        int $userId,
        EmailAddress $emailAddress,
        ExternalIdentity $identity,
    ): void {
        $provider = $identity->provider()->toString();

        $this->connection->execute(
            <<<'SQL'
INSERT INTO [identity].[ExternalIdentities] (
    [UserId],
    [ProviderCode],
    [TenantIdentifier],
    [SubjectIdentifier],
    [EmailClaim],
    [NormalizedEmailClaim],
    [IsActive]
)
VALUES (
    :userId,
    :provider,
    :tenant,
    :subject,
    :emailClaim,
    :normalizedEmailClaim,
    1
)
SQL,
            [
                ':userId' => $userId,
                ':provider' => $provider,
                ':tenant' => $identity->tenantId()->toString(),
                ':subject' => $identity->subjectId()->toString(),
                ':emailClaim' => $emailAddress->toString(),
                ':normalizedEmailClaim' => $emailAddress->toString(),
            ],
        );
    }

    private function assignRole(int $userId, RoleCode $role): void
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [RoleId]
FROM [identity].[Roles]
WHERE UPPER([RoleCode]) = :roleCode
  AND [IsActive] = 1
SQL,
            [':roleCode' => $role->toString()],
        );

        if ($rows === []) {
            throw IdentityPersistenceException::roleNotFound($role->toString());
        }

        $roleId = AzureSqlValueMapper::intValue($rows[0]['RoleId'] ?? null);

        $this->connection->execute(
            <<<'SQL'
INSERT INTO [identity].[UserRoles] (
    [UserId],
    [RoleId],
    [IsActive]
)
VALUES (
    :userId,
    :roleId,
    1
)
SQL,
            [
                ':userId' => $userId,
                ':roleId' => $roleId,
            ],
        );
    }
}
