<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateUserService;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Module;

/**
 * Onboarding's one reach into platform identity, against `tricab-mel` (4.1).
 *
 * ## It writes through identity's own code, with one exception
 *
 * The account is created by {@see CreateUserService}, the service Admin → User
 * Management uses, so the new account has a `USER_CREATED` row in
 * `audit.UserManagementEvents` naming the HR user who caused it — the same
 * trail as an account an administrator made by hand.
 *
 * The exception is classifying the address as personal in
 * `[identity].[UserEmailAddresses]`. Phase P added the table and the binding
 * policy but no write path for a new account, and `createUser()` does not
 * insert a row there. Without one the new address is unclassified, and an
 * unclassified address may resolve a Microsoft sign-in — exactly what a
 * personal address must not do (invariant 16). The insert is one row, for an
 * account created in the same transaction, and never touches an address that
 * already exists.
 *
 * Every read here is of `[identity]` alone.
 */
final class AzureSqlOnboardingAccountDirectory implements OnboardingAccountDirectory
{
    private ?bool $addressTableExists = null;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly CreateUserService $createUser,
    ) {
    }

    public function findUserIdByEmail(EmailAddress $emailAddress): ?int
    {
        $normalized = strtolower(trim($emailAddress->toString()));

        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :email',
            [':email' => $normalized],
        );
        if ($rows === [] && $this->addressTableExists()) {
            // A second address somebody already holds is theirs too.
            $rows = $this->connection->fetchAll(
                'SELECT TOP (1) [UserId] FROM [identity].[UserEmailAddresses] WHERE [NormalizedEmailAddress] = :email AND [IsActive] = 1',
                [':email' => $normalized],
            );
        }

        return $rows === [] ? null : AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? 0);
    }

    public function createGuestAccount(ActorContext $actor, EmailAddress $emailAddress, string $displayName): int
    {
        $this->createUser->execute($actor, $emailAddress->toString(), $displayName, UserStatus::ACTIVE, [Module::ROLE_GUEST]);

        $userId = $this->findUserIdByEmail($emailAddress)
            ?? throw new \RuntimeException('The account just created could not be found by its address.');

        if ($this->addressTableExists()) {
            $this->connection->execute(
                <<<'SQL'
                INSERT INTO [identity].[UserEmailAddresses] ([UserId], [EmailAddress], [NormalizedEmailAddress], [Purpose], [IsActive])
                SELECT :userId, :email, :normalized, N'personal', 1
                WHERE NOT EXISTS (SELECT 1 FROM [identity].[UserEmailAddresses] WHERE [NormalizedEmailAddress] = :normalizedCheck)
                SQL,
                [
                    ':userId' => $userId,
                    ':email' => $emailAddress->toString(),
                    ':normalized' => strtolower(trim($emailAddress->toString())),
                    ':normalizedCheck' => strtolower(trim($emailAddress->toString())),
                ],
            );
        }

        return $userId;
    }

    public function canSignIn(int $userId): bool
    {
        // The email-code sign-in's own question (AzureSqlGuestLoginEligibilityLookup),
        // asked by user id: active, and holding account.login through an active,
        // unexpired role.
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT CASE WHEN LOWER(s.[StatusCode]) = N'active' AND s.[AllowsLogin] = 1 AND EXISTS (
                SELECT 1
                FROM [identity].[UserRoles] ur
                JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
                JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
                JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId] AND p.[IsActive] = 1
                    AND UPPER(p.[PermissionCode]) = UPPER(:loginPermission)
                WHERE ur.[UserId] = u.[UserId]
                  AND ur.[IsActive] = 1
                  AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
            ) THEN 1 ELSE 0 END AS [CanSignIn]
            FROM [identity].[Users] u
            JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[IsActive] = 1
            WHERE u.[UserId] = :userId
            SQL,
            [':loginPermission' => ProtectedIdentityCatalog::PERMISSION_ACCOUNT_LOGIN, ':userId' => $userId],
        );

        return $rows !== [] && AzureSqlValueMapper::boolValue($rows[0]['CanSignIn'] ?? false);
    }

    private function addressTableExists(): bool
    {
        if ($this->addressTableExists !== null) {
            return $this->addressTableExists;
        }

        try {
            $rows = $this->connection->fetchAll("SELECT OBJECT_ID(N'[identity].[UserEmailAddresses]', N'U') AS [ObjectId]");
            $this->addressTableExists = $rows !== [] && ($rows[0]['ObjectId'] ?? null) !== null;
        } catch (Throwable) {
            $this->addressTableExists = false;
        }

        return $this->addressTableExists;
    }
}
