<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Development/test UserRepository + provisioning store.
 * Seeds one Entra employee and allows guest user registration.
 */
final class InMemoryApiUserRepository implements UserRepository, UserProvisioningRepository
{
    /** @var array<int, User> */
    private array $usersById = [];

    /** @var array<string, int> */
    private array $userIdsByEmail = [];

    /** @var array<string, int> */
    private array $userIdsByExternalIdentity = [];

    /** @var array<int, array<string, string>> */
    private array $providerSubjectsByUserId = [];

    private int $nextId = 2;

    private readonly UuidV4Generator $uuids;

    public function __construct()
    {
        $this->uuids = new UuidV4Generator();

        $permissionCodes = ['account.login', ...DevelopmentUserManagementCatalog::managementPermissionCodes()];

        $employee = new User(
            UserId::fromInt(1),
            UserPublicId::fromString(DevelopmentUserManagementCatalog::USER_PUBLIC_ID),
            EmailAddress::fromString(DevelopmentUserManagementCatalog::USER_EMAIL),
            DevelopmentUserManagementCatalog::USER_DISPLAY_NAME,
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE'), RoleCode::fromString('ADMINISTRATOR')],
            array_map(
                static fn (string $code): PermissionCode => PermissionCode::fromString($code),
                $permissionCodes,
            ),
        );

        $identity = new ExternalIdentity(
            ExternalProvider::fromString(ExternalProvider::MICROSOFT_ENTRA),
            ExternalSubjectId::fromString(DevelopmentUserManagementCatalog::USER_SUBJECT),
            ExternalTenantId::fromString('development-tenant'),
        );

        $this->persist($employee, $identity);
    }

    /**
     * Replaces the active user's roles/permissions in place, for tests that
     * need to exercise the 403 INSUFFICIENT_PERMISSION path without disabling
     * the whole account.
     *
     * @param list<string> $permissionCodes
     * @param list<string> $roleCodes
     */
    public function replacePermissions(int $userId, array $permissionCodes, array $roleCodes = ['EMPLOYEE']): void
    {
        $existing = $this->usersById[$userId] ?? null;
        if ($existing === null) {
            return;
        }

        $updated = new User(
            $existing->id(),
            $existing->publicId(),
            $existing->emailAddress(),
            $existing->displayName(),
            $existing->status(),
            array_map(static fn (string $code): RoleCode => RoleCode::fromString($code), $roleCodes),
            array_map(static fn (string $code): PermissionCode => PermissionCode::fromString($code), $permissionCodes),
        );

        $this->usersById[$userId] = $updated;
    }

    public function findById(UserId $userId): ?User
    {
        return $this->usersById[$userId->toInt()] ?? null;
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        $userId = $this->userIdsByEmail[$emailAddress->toString()] ?? null;

        return $userId === null ? null : ($this->usersById[$userId] ?? null);
    }

    /**
     * @return list<User>
     */
    public function findAllByEmail(EmailAddress $emailAddress): array
    {
        $matches = [];
        foreach ($this->usersById as $user) {
            if ($user->emailAddress()->equals($emailAddress)) {
                $matches[] = $user;
            }
        }

        return $matches;
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        $userId = $this->userIdsByExternalIdentity[$this->externalIdentityKey($identity)] ?? null;

        return $userId === null ? null : ($this->usersById[$userId] ?? null);
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
        $user = new User(
            UserId::fromInt($this->nextId),
            UserPublicId::fromString($this->uuids->generate()->toString()),
            $emailAddress,
            $displayName,
            $status,
            $roles,
            $permissions,
        );
        ++$this->nextId;

        $this->persist($user, $identity);

        return $user;
    }

    public function linkExternalIdentity(User $user, ExternalIdentity $identity): void
    {
        $this->persist($user, $identity);
    }

    public function findActiveProviderSubjectForUser(UserId $userId, ExternalProvider $provider): ?ExternalSubjectId
    {
        $subject = $this->providerSubjectsByUserId[$userId->toInt()][$provider->toString()] ?? null;

        return $subject !== null ? ExternalSubjectId::fromString($subject) : null;
    }

    public function externalIdentityCount(): int
    {
        return count($this->userIdsByExternalIdentity);
    }

    private function persist(User $user, ?ExternalIdentity $externalIdentity = null): void
    {
        $userId = $user->id()->toInt();
        $this->usersById[$userId] = $user;
        $this->userIdsByEmail[$user->emailAddress()->toString()] = $userId;

        if ($externalIdentity !== null) {
            $this->userIdsByExternalIdentity[$this->externalIdentityKey($externalIdentity)] = $userId;
            $this->providerSubjectsByUserId[$userId][$externalIdentity->provider()->toString()]
                = $externalIdentity->subjectId()->toString();
        }
    }

    private function externalIdentityKey(ExternalIdentity $identity): string
    {
        return implode('|', [
            $identity->provider()->toString(),
            $identity->subjectId()->toString(),
            $identity->tenantId()->toString(),
        ]);
    }
}
