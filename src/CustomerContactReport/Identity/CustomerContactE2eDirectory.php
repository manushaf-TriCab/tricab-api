<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Identity;

use TriCab\Api\Customer\Persistence\InMemory\InMemoryCustomerStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryUserManagementStore;
use TriCab\Core\Customer\Domain\ValueObject\CustomerCode;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Synthetic browser identities. They are added only while E2E mode is on.
 * They are not production users and they are not grants on EMPLOYEE or ADMINISTRATOR.
 */
final class CustomerContactE2eDirectory
{
    public const TENANT = 'development-tenant';

    public const CUSTOMER_CODE = 'E2E-CABLE';

    public const ACCOUNTS_LOGIN = 'cc-e2e-accounts@tricab.test';

    public const CREATOR_LOGIN = 'cc-e2e-creator@tricab.test';

    public const MANAGER_LOGIN = 'cc-e2e-manager@tricab.test';

    public const UPPER_LOGIN = 'cc-e2e-upper@tricab.test';

    /**
     * @return list<array{subject: string, email: string, name: string, permissions: list<string>}>
     */
    public static function identities(): array
    {
        $shell = ['ACCOUNT.LOGIN', 'CUSTOMER.READ', 'USER.READ'];

        return [
            self::person('cc-e2e-creator', self::CREATOR_LOGIN, 'E2E Creator', [
                ...$shell,
                Module::PERMISSION_ACCESS,
                Module::PERMISSION_READ,
                Module::PERMISSION_CREATE,
                Module::PERMISSION_COMPLETE,
                Module::PERMISSION_ICAR_SIGN,
            ]),
            self::person('cc-e2e-assignee', 'cc-e2e-assignee@tricab.test', 'E2E Assignee', [
                ...$shell,
                Module::PERMISSION_ACCESS,
                Module::PERMISSION_READ,
                Module::PERMISSION_COMPLETE,
                Module::PERMISSION_ICAR_SIGN,
            ]),
            self::person('cc-e2e-coordinator', 'cc-e2e-coordinator@tricab.test', 'E2E Coordinator', [
                ...$shell,
                Module::PERMISSION_ACCESS,
                Module::PERMISSION_READ,
                Module::PERMISSION_READ_ALL,
                Module::PERMISSION_CREATE,
                Module::PERMISSION_ASSIGN,
                Module::PERMISSION_CLOSE,
                Module::PERMISSION_REOPEN,
                Module::PERMISSION_REMEDY,
            ]),
            self::person('cc-e2e-manager', self::MANAGER_LOGIN, 'E2E Manager', [
                ...$shell,
                Module::PERMISSION_ACCESS,
                Module::PERMISSION_READ,
                Module::PERMISSION_APPROVE,
            ]),
            self::person('cc-e2e-upper', self::UPPER_LOGIN, 'E2E Upper Manager', [
                ...$shell,
                Module::PERMISSION_ACCESS,
                Module::PERMISSION_READ,
                Module::PERMISSION_APPROVE,
            ]),
            self::person('cc-e2e-accounts', self::ACCOUNTS_LOGIN, 'E2E Accounts', [
                ...$shell,
                Module::PERMISSION_ACCESS,
                Module::PERMISSION_READ,
            ]),
            self::person('cc-e2e-readall', 'cc-e2e-readall@tricab.test', 'E2E Read All', [
                ...$shell,
                Module::PERMISSION_ACCESS,
                Module::PERMISSION_READ,
                Module::PERMISSION_READ_ALL,
            ]),
            self::person('cc-e2e-other', 'cc-e2e-other@tricab.test', 'E2E Other Employee', [
                ...$shell,
                Module::PERMISSION_ACCESS,
                Module::PERMISSION_READ,
            ]),
        ];
    }

    public static function managerEmail(string $login): ?string
    {
        return match (strtolower(trim($login))) {
            self::CREATOR_LOGIN => self::MANAGER_LOGIN,
            self::MANAGER_LOGIN => self::UPPER_LOGIN,
            default => null,
        };
    }

    public static function seedAuthentication(InMemoryApiUserRepository $users): void
    {
        foreach (self::identities() as $identity) {
            $email = EmailAddress::fromString($identity['email']);
            if ($users->findByEmail($email) !== null) {
                continue;
            }

            $users->register(
                $email,
                $identity['name'],
                UserStatus::active(),
                [RoleCode::fromString(ProtectedIdentityCatalog::ROLE_EMPLOYEE)],
                array_map(
                    static fn (string $code): PermissionCode => PermissionCode::fromString($code),
                    $identity['permissions'],
                ),
                new ExternalIdentity(
                    ExternalProvider::fromString(ExternalProvider::MICROSOFT_ENTRA),
                    ExternalSubjectId::fromString($identity['subject']),
                    ExternalTenantId::fromString(self::TENANT),
                ),
            );
        }
    }

    public static function seedDirectory(InMemoryUserManagementStore $store): void
    {
        foreach (self::identities() as $identity) {
            $store->seedUser(
                $identity['email'],
                $identity['name'],
                UserStatus::ACTIVE,
                [ProtectedIdentityCatalog::ROLE_EMPLOYEE],
            );
        }
    }

    public static function seedCustomer(InMemoryCustomerStore $store): void
    {
        $store->create(
            CustomerCode::fromString(self::CUSTOMER_CODE),
            'E2E Cable Customer',
            CustomerStatus::active(),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            UserId::fromInt(1),
        );
    }

    /**
     * @param list<string> $permissions
     * @return array{subject: string, email: string, name: string, permissions: list<string>}
     */
    private static function person(string $subject, string $email, string $name, array $permissions): array
    {
        return [
            'subject' => $subject,
            'email' => $email,
            'name' => $name,
            'permissions' => $permissions,
        ];
    }
}
