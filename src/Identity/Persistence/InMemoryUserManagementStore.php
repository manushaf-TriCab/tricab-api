<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Identity\Application\DTO\UserManagement\EffectivePermissionGrant;
use TriCab\Core\Identity\Application\DTO\UserManagement\IdentityProviderSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\Pagination;
use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\RolePermissionAssignment;
use TriCab\Core\Identity\Application\DTO\UserManagement\RolePermissionSource;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListResult;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditEntry;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditListResult;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditWrite;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementSummaryCounts;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserRoleAssignment;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserSummary;
use TriCab\Core\Identity\Application\Service\UserManagement\EffectivePermissionCalculator;
use TriCab\Core\Identity\Contract\PermissionManagementRepository;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Contract\UserManagementAuditRepository;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\Service\EmailMasker;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * In-memory user-management persistence for USER_REPOSITORY=memory mode.
 * Adapted from tricab-core's InMemoryUserManagementStore test double into a
 * production-safe namespace so the API can wire the four Core user-management
 * contracts without a database.
 *
 * @phpstan-type StoredUser array{
 *     id: int,
 *     publicId: string,
 *     emailAddress: string,
 *     displayName: string,
 *     status: string,
 *     createdAtUtc: string,
 *     updatedAtUtc: string,
 *     lastLoginAtUtc: string|null,
 * }
 * @phpstan-type StoredRole array{
 *     id: int,
 *     publicId: string,
 *     roleCode: RoleCode,
 *     roleName: string,
 *     description: string|null,
 *     isSystemRole: bool,
 *     isActive: bool,
 *     createdAtUtc: string,
 *     updatedAtUtc: string,
 * }
 * @phpstan-type StoredPermission array{
 *     id: int,
 *     publicId: string,
 *     permissionCode: PermissionCode,
 *     permissionName: string,
 *     description: string|null,
 *     moduleCode: string,
 *     isActive: bool,
 *     createdAtUtc: string,
 *     updatedAtUtc: string,
 * }
 * @phpstan-type StoredUserRoleAssignment array{
 *     userId: int,
 *     roleCode: RoleCode,
 *     isActive: bool,
 *     assignedAtUtc: string,
 *     expiresAtUtc: string|null,
 * }
 * @phpstan-type StoredRolePermissionAssignment array{
 *     roleCode: RoleCode,
 *     permissionCode: PermissionCode,
 *     isActive: bool,
 *     assignedAtUtc: string,
 * }
 * @phpstan-type StoredIdentityProvider array{
 *     userId: int,
 *     providerCode: string,
 *     subject: string,
 *     isLinked: bool,
 *     isActive: bool,
 *     lastAuthenticatedAtUtc: string|null,
 * }
 */
final class InMemoryUserManagementStore
{
    private readonly InMemoryUserManagementUserRepository $userRepository;
    private readonly InMemoryUserManagementRoleRepository $roleRepository;
    private readonly InMemoryUserManagementPermissionRepository $permissionRepository;
    private readonly InMemoryUserManagementAuditRepository $auditRepository;

    /** @var array<int, StoredUser> */
    private array $users = [];

    /** @var array<string, int> */
    private array $userIdsByEmail = [];

    /** @var array<int, StoredRole> */
    private array $roles = [];

    /** @var array<string, int> */
    private array $roleIdsByCode = [];

    /** @var array<int, StoredPermission> */
    private array $permissions = [];

    /** @var array<string, int> */
    private array $permissionIdsByCode = [];

    /** @var list<StoredUserRoleAssignment> */
    private array $userRoleAssignments = [];

    /** @var list<StoredRolePermissionAssignment> */
    private array $rolePermissionAssignments = [];

    /** @var list<StoredIdentityProvider> */
    private array $identityProviders = [];

    /** @var list<UserManagementAuditEntry> */
    private array $auditEntries = [];

    private int $nextUserId = 1;
    private int $nextRoleId = 1;
    private int $nextPermissionId = 1;

    private readonly UuidV4Generator $uuids;
    private readonly EffectivePermissionCalculator $permissionCalculator;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->uuids = new UuidV4Generator();
        $this->permissionCalculator = new EffectivePermissionCalculator();
        $this->userRepository = new InMemoryUserManagementUserRepository($this);
        $this->roleRepository = new InMemoryUserManagementRoleRepository($this);
        $this->permissionRepository = new InMemoryUserManagementPermissionRepository($this);
        $this->auditRepository = new InMemoryUserManagementAuditRepository($this);

        $this->seedSystemCatalog();
        $this->seedDevelopmentUserManagement();
    }

    public function users(): UserManagementRepository
    {
        return $this->userRepository;
    }

    public function roles(): RoleManagementRepository
    {
        return $this->roleRepository;
    }

    public function permissions(): PermissionManagementRepository
    {
        return $this->permissionRepository;
    }

    public function audit(): UserManagementAuditRepository
    {
        return $this->auditRepository;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    /**
     * Seeds canonical system roles and the ACCOUNT.LOGIN permission.
     */
    public function seedSystemCatalog(int $actorUserId = 1): void
    {
        if ($this->roles !== []) {
            return;
        }

        $this->seedRole(
            ProtectedIdentityCatalog::ROLE_EMPLOYEE,
            'Employee',
            'Default employee role',
            true,
            $actorUserId,
        );
        $this->seedRole(
            ProtectedIdentityCatalog::ROLE_GUEST,
            'Guest',
            'Guest access role',
            true,
            $actorUserId,
        );
        $this->seedRole(
            ProtectedIdentityCatalog::ROLE_ADMINISTRATOR,
            'Administrator',
            'Full administrative access',
            true,
            $actorUserId,
        );
        $this->seedPermission(
            ProtectedIdentityCatalog::PERMISSION_ACCOUNT_LOGIN,
            'Account Login',
            'Allows authentication',
            'ACCOUNT',
            true,
            $actorUserId,
        );

        $this->assignPermission(
            RoleCode::fromString(ProtectedIdentityCatalog::ROLE_GUEST),
            PermissionCode::fromString(ProtectedIdentityCatalog::PERMISSION_ACCOUNT_LOGIN),
            UserId::fromInt($actorUserId),
        );
        $this->assignPermission(
            RoleCode::fromString(ProtectedIdentityCatalog::ROLE_EMPLOYEE),
            PermissionCode::fromString(ProtectedIdentityCatalog::PERMISSION_ACCOUNT_LOGIN),
            UserId::fromInt($actorUserId),
        );
        $this->assignPermission(
            RoleCode::fromString(ProtectedIdentityCatalog::ROLE_ADMINISTRATOR),
            PermissionCode::fromString(ProtectedIdentityCatalog::PERMISSION_ACCOUNT_LOGIN),
            UserId::fromInt($actorUserId),
        );
    }

    /**
     * Seeds the same development smoke user as InMemoryApiUserRepository
     * (id=1, same publicId/email) with EMPLOYEE + ADMINISTRATOR roles, a
     * linked Microsoft Entra identity, and grants the 16 admin
     * user-management permissions plus ACCOUNT.LOGIN to the ADMINISTRATOR
     * role so /me and /api/v1/admin/* agree in memory mode.
     */
    public function seedDevelopmentUserManagement(int $actorUserId = 1): void
    {
        foreach (DevelopmentUserManagementCatalog::managementPermissions() as $code => $meta) {
            $this->seedPermission($code, $meta['name'], null, $meta['module'], true, $actorUserId);
        }

        $administratorRole = RoleCode::fromString(ProtectedIdentityCatalog::ROLE_ADMINISTRATOR);
        foreach (DevelopmentUserManagementCatalog::managementPermissionCodes() as $code) {
            $this->assignPermission(
                $administratorRole,
                PermissionCode::fromString($code),
                UserId::fromInt($actorUserId),
            );
        }

        if ($this->emailExists(EmailAddress::fromString(DevelopmentUserManagementCatalog::USER_EMAIL))) {
            return;
        }

        $this->seedUserWithPublicId(
            publicId: DevelopmentUserManagementCatalog::USER_PUBLIC_ID,
            emailAddress: DevelopmentUserManagementCatalog::USER_EMAIL,
            displayName: DevelopmentUserManagementCatalog::USER_DISPLAY_NAME,
            roleCodes: [ProtectedIdentityCatalog::ROLE_EMPLOYEE, ProtectedIdentityCatalog::ROLE_ADMINISTRATOR],
            actorUserId: $actorUserId,
        );

        $this->linkIdentityProvider(
            userPublicId: DevelopmentUserManagementCatalog::USER_PUBLIC_ID,
            providerCode: ExternalProvider::MICROSOFT_ENTRA,
            subject: DevelopmentUserManagementCatalog::USER_SUBJECT,
            isActive: true,
        );
    }

    /**
     * Seeds a user with an explicit public id (needed to keep this store in
     * sync with InMemoryApiUserRepository's fixed development user). Role
     * assignments key off the internal integer userId, so patching the
     * publicId after creation is safe.
     *
     * @param list<string> $roleCodes
     */
    public function seedUserWithPublicId(
        string $publicId,
        string $emailAddress,
        string $displayName,
        string $status = UserStatus::ACTIVE,
        array $roleCodes = [],
        int $actorUserId = 1,
    ): UserDetails {
        $created = $this->seedUser($emailAddress, $displayName, $status, $roleCodes, $actorUserId);
        $userId = $this->requireUserIdByPublicId(UserPublicId::fromString($created->publicId));
        $this->users[$userId]['publicId'] = $publicId;

        return $this->buildUserDetails($userId);
    }

    public function seedRole(
        string $roleCode,
        string $roleName,
        ?string $description = null,
        bool $isActive = true,
        int $actorUserId = 1,
    ): RoleDetails {
        $code = RoleCode::fromString($roleCode);

        if ($this->roleCodeExists($code)) {
            $existing = $this->findRoleByCode($code);
            assert($existing instanceof RoleDetails);

            return $existing;
        }

        return $this->createRole(
            roleCode: $code,
            roleName: $roleName,
            description: $description,
            isActive: $isActive,
            actorUserId: UserId::fromInt($actorUserId),
        );
    }

    public function seedPermission(
        string $permissionCode,
        string $permissionName,
        ?string $description,
        string $moduleCode,
        bool $isActive = true,
        int $actorUserId = 1,
    ): PermissionSummary {
        $code = PermissionCode::fromString($permissionCode);

        if ($this->permissionCodeExists($code)) {
            $existing = $this->findPermissionByCode($code);
            assert($existing instanceof PermissionSummary);

            return $existing;
        }

        return $this->createPermission(
            permissionCode: $code,
            permissionName: $permissionName,
            description: $description,
            moduleCode: $moduleCode,
            isActive: $isActive,
            actorUserId: UserId::fromInt($actorUserId),
        );
    }

    /**
     * @param list<string> $roleCodes
     */
    public function seedUser(
        string $emailAddress,
        string $displayName,
        string $status = UserStatus::ACTIVE,
        array $roleCodes = [],
        int $actorUserId = 1,
    ): UserDetails {
        $email = EmailAddress::fromString($emailAddress);
        $resolvedRoles = array_map(
            static fn (string $code): RoleCode => RoleCode::fromString($code),
            $roleCodes,
        );

        return $this->createUser(
            emailAddress: $email,
            displayName: $displayName,
            status: UserStatus::fromString($status),
            roleCodes: $resolvedRoles,
            actorUserId: UserId::fromInt($actorUserId),
        );
    }

    public function linkIdentityProvider(
        string $userPublicId,
        string $providerCode,
        string $subject,
        bool $isActive = true,
        ?string $lastAuthenticatedAtUtc = null,
    ): void {
        $userId = $this->requireUserIdByPublicId(UserPublicId::fromString($userPublicId));
        $provider = ExternalProvider::fromString($providerCode);

        foreach ($this->identityProviders as $index => $record) {
            if ($record['userId'] === $userId && $record['providerCode'] === $provider->toString()) {
                $this->identityProviders[$index]['subject'] = $subject;
                $this->identityProviders[$index]['isLinked'] = true;
                $this->identityProviders[$index]['isActive'] = $isActive;
                $this->identityProviders[$index]['lastAuthenticatedAtUtc'] = $lastAuthenticatedAtUtc;

                return;
            }
        }

        $this->identityProviders[] = [
            'userId' => $userId,
            'providerCode' => $provider->toString(),
            'subject' => $subject,
            'isLinked' => true,
            'isActive' => $isActive,
            'lastAuthenticatedAtUtc' => $lastAuthenticatedAtUtc,
        ];
    }

    public function assignRoleWithExpiry(
        string $userPublicId,
        string $roleCode,
        ?DateTimeImmutable $expiresAtUtc,
        int $actorUserId = 1,
    ): void {
        $this->assignRole(
            publicId: UserPublicId::fromString($userPublicId),
            roleCode: RoleCode::fromString($roleCode),
            expiresAtUtc: $expiresAtUtc,
            actorUserId: UserId::fromInt($actorUserId),
        );
    }

    /** @return list<UserManagementAuditEntry> */
    public function allAuditEntries(): array
    {
        return $this->auditEntries;
    }

    public function listUsers(UserListCriteria $criteria): UserListResult
    {
        $items = [];
        foreach ($this->users as $user) {
            $publicId = UserPublicId::fromString($user['publicId']);
            $summary = $this->buildUserSummary($publicId);
            if ($summary === null) {
                continue;
            }

            if (!$this->matchesUserListCriteria($summary, $criteria)) {
                continue;
            }

            $items[] = $summary;
        }

        usort(
            $items,
            fn (UserSummary $a, UserSummary $b): int => $this->compareUserSummaries($a, $b, $criteria),
        );

        $total = count($items);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $pageItems = array_slice($items, $offset, $criteria->pageSize);

        return new UserListResult(
            items: $pageItems,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
            counts: $this->summarizeCounts(),
        );
    }

    public function findDetailsByPublicId(UserPublicId $publicId): ?UserDetails
    {
        $userId = $this->userIdsByPublicId($publicId);
        if ($userId === null) {
            return null;
        }

        return $this->buildUserDetails($userId);
    }

    public function findUserIdByPublicId(UserPublicId $publicId): ?UserId
    {
        $userId = $this->userIdsByPublicId($publicId);

        return $userId !== null ? UserId::fromInt($userId) : null;
    }

    public function emailExists(EmailAddress $emailAddress, ?UserPublicId $exceptPublicId = null): bool
    {
        $userId = $this->userIdsByEmail[$emailAddress->toString()] ?? null;
        if ($userId === null) {
            return false;
        }

        if ($exceptPublicId === null) {
            return true;
        }

        $exceptId = $this->userIdsByPublicId($exceptPublicId);

        return $exceptId !== $userId;
    }

    /**
     * @param list<RoleCode> $roleCodes
     */
    public function createUser(
        EmailAddress $emailAddress,
        string $displayName,
        UserStatus $status,
        array $roleCodes,
        UserId $actorUserId,
    ): UserDetails {
        if ($this->emailExists($emailAddress)) {
            throw new \RuntimeException(sprintf('Email "%s" already exists.', $emailAddress->toString()));
        }

        $now = $this->timestampUtc();
        $userId = $this->nextUserId++;
        $publicId = $this->uuids->generate()->toString();

        $this->users[$userId] = [
            'id' => $userId,
            'publicId' => $publicId,
            'emailAddress' => $emailAddress->toString(),
            'displayName' => $displayName,
            'status' => $status->toString(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
            'lastLoginAtUtc' => null,
        ];
        $this->userIdsByEmail[$emailAddress->toString()] = $userId;

        foreach ($roleCodes as $roleCode) {
            $this->assignRole(UserPublicId::fromString($publicId), $roleCode, null, $actorUserId);
        }

        return $this->buildUserDetails($userId);
    }

    public function updateUser(
        UserPublicId $publicId,
        string $displayName,
        ?EmailAddress $emailAddress,
        UserId $actorUserId,
    ): UserDetails {
        $userId = $this->requireUserIdByPublicId($publicId);
        $existing = $this->users[$userId];
        $email = $existing['emailAddress'];

        if ($emailAddress !== null) {
            if ($this->emailExists($emailAddress, $publicId)) {
                throw new \RuntimeException(sprintf('Email "%s" already exists.', $emailAddress->toString()));
            }

            unset($this->userIdsByEmail[$email]);
            $email = $emailAddress->toString();
            $this->userIdsByEmail[$email] = $userId;
        }

        $this->users[$userId] = [
            ...$existing,
            'emailAddress' => $email,
            'displayName' => $displayName,
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildUserDetails($userId);
    }

    public function changeStatus(
        UserPublicId $publicId,
        UserStatus $status,
        UserId $actorUserId,
    ): UserDetails {
        $userId = $this->requireUserIdByPublicId($publicId);
        $existing = $this->users[$userId];
        $this->users[$userId] = [
            ...$existing,
            'status' => $status->toString(),
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildUserDetails($userId);
    }

    public function assignRole(
        UserPublicId $publicId,
        RoleCode $roleCode,
        ?DateTimeImmutable $expiresAtUtc,
        UserId $actorUserId,
    ): void {
        $userId = $this->requireUserIdByPublicId($publicId);
        $now = $this->timestampUtc();
        $expiresAt = $expiresAtUtc?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);

        foreach ($this->userRoleAssignments as $index => $assignment) {
            if ($assignment['userId'] !== $userId || !$assignment['roleCode']->equals($roleCode)) {
                continue;
            }

            if ($assignment['isActive'] && !$this->isExpiredUtc($assignment['expiresAtUtc'])) {
                return;
            }

            $this->userRoleAssignments[$index]['isActive'] = true;
            $this->userRoleAssignments[$index]['assignedAtUtc'] = $now;
            $this->userRoleAssignments[$index]['expiresAtUtc'] = $expiresAt;
            $this->touchUser($userId);

            return;
        }

        $this->userRoleAssignments[] = [
            'userId' => $userId,
            'roleCode' => $roleCode,
            'isActive' => true,
            'assignedAtUtc' => $now,
            'expiresAtUtc' => $expiresAt,
        ];
        $this->touchUser($userId);
    }

    public function removeRole(UserPublicId $publicId, RoleCode $roleCode): bool
    {
        $userId = $this->requireUserIdByPublicId($publicId);
        $removed = false;

        foreach ($this->userRoleAssignments as $index => $assignment) {
            if (
                $assignment['userId'] === $userId
                && $assignment['roleCode']->equals($roleCode)
                && $assignment['isActive']
            ) {
                $this->userRoleAssignments[$index]['isActive'] = false;
                $removed = true;
            }
        }

        if ($removed) {
            $this->touchUser($userId);
        }

        return $removed;
    }

    public function userHasActiveRole(UserPublicId $publicId, RoleCode $roleCode): bool
    {
        $userId = $this->userIdsByPublicId($publicId);
        if ($userId === null) {
            return false;
        }

        foreach ($this->userRoleAssignments as $assignment) {
            if (
                $assignment['userId'] === $userId
                && $assignment['roleCode']->equals($roleCode)
                && $assignment['isActive']
                && !$this->isExpiredUtc($assignment['expiresAtUtc'])
            ) {
                return true;
            }
        }

        return false;
    }

    public function countActiveUsersWithRole(RoleCode $roleCode): int
    {
        $count = 0;

        foreach ($this->users as $user) {
            $status = UserStatus::fromString($user['status']);
            if (!$status->isActive()) {
                continue;
            }

            $publicId = UserPublicId::fromString($user['publicId']);
            if ($this->userHasActiveRole($publicId, $roleCode)) {
                ++$count;
            }
        }

        return $count;
    }

    public function summarizeCounts(): UserManagementSummaryCounts
    {
        $total = count($this->users);
        $active = 0;
        $suspended = 0;
        $guest = 0;
        $microsoft = 0;
        $administrators = 0;
        $guestRole = ProtectedIdentityCatalog::guest();
        $adminRole = ProtectedIdentityCatalog::administrator();

        foreach ($this->users as $user) {
            $status = UserStatus::fromString($user['status']);
            if ($status->isActive()) {
                ++$active;
            }
            if ($status->isDisabled()) {
                ++$suspended;
            }

            $publicId = UserPublicId::fromString($user['publicId']);
            if ($this->userHasActiveRole($publicId, $guestRole)) {
                ++$guest;
            }
            if ($this->userHasActiveRole($publicId, $adminRole)) {
                ++$administrators;
            }
            if ($this->userHasLinkedProvider($user['id'], ExternalProvider::MICROSOFT_ENTRA)) {
                ++$microsoft;
            }
        }

        return new UserManagementSummaryCounts(
            totalUsers: $total,
            activeUsers: $active,
            suspendedUsers: $suspended,
            guestUsers: $guest,
            microsoftUsers: $microsoft,
            administrators: $administrators,
        );
    }

    /**
     * @return list<RoleSummary>
     */
    public function listRoles(?string $search = null, ?bool $isActive = null): array
    {
        $items = [];
        foreach ($this->roles as $role) {
            if ($isActive !== null && $role['isActive'] !== $isActive) {
                continue;
            }

            if ($search !== null && !$this->matchesSearch($search, [$role['roleCode']->toString(), $role['roleName']])) {
                continue;
            }

            $items[] = $this->buildRoleSummary($role['id']);
        }

        usort($items, static fn (RoleSummary $a, RoleSummary $b): int => strcmp($a->roleCode, $b->roleCode));

        return $items;
    }

    public function findRoleByCode(RoleCode $roleCode): ?RoleDetails
    {
        $roleId = $this->roleIdsByCode[$roleCode->toString()] ?? null;
        if ($roleId === null) {
            return null;
        }

        return $this->buildRoleDetails($roleId);
    }

    public function findRoleIdByCode(RoleCode $roleCode): ?int
    {
        return $this->roleIdsByCode[$roleCode->toString()] ?? null;
    }

    public function roleCodeExists(RoleCode $roleCode): bool
    {
        return isset($this->roleIdsByCode[$roleCode->toString()]);
    }

    public function isRoleActive(RoleCode $roleCode): bool
    {
        $roleId = $this->roleIdsByCode[$roleCode->toString()] ?? null;
        if ($roleId === null) {
            return false;
        }

        return $this->roles[$roleId]['isActive'];
    }

    public function createRole(
        RoleCode $roleCode,
        string $roleName,
        ?string $description,
        bool $isActive,
        UserId $actorUserId,
    ): RoleDetails {
        if ($this->roleCodeExists($roleCode)) {
            throw new \RuntimeException(sprintf('Role code "%s" already exists.', $roleCode->toString()));
        }

        $now = $this->timestampUtc();
        $roleId = $this->nextRoleId++;

        $this->roles[$roleId] = [
            'id' => $roleId,
            'publicId' => $this->uuids->generate()->toString(),
            'roleCode' => $roleCode,
            'roleName' => $roleName,
            'description' => $description,
            'isSystemRole' => ProtectedIdentityCatalog::isSystemProtectedRole($roleCode),
            'isActive' => $isActive,
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
        ];
        $this->roleIdsByCode[$roleCode->toString()] = $roleId;

        return $this->buildRoleDetails($roleId);
    }

    public function updateRole(
        RoleCode $roleCode,
        string $roleName,
        ?string $description,
        UserId $actorUserId,
    ): RoleDetails {
        $roleId = $this->requireRoleIdByCode($roleCode);
        $existing = $this->roles[$roleId];
        $this->roles[$roleId] = [
            ...$existing,
            'roleName' => $roleName,
            'description' => $description,
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildRoleDetails($roleId);
    }

    public function changeRoleStatus(
        RoleCode $roleCode,
        bool $isActive,
        UserId $actorUserId,
    ): RoleDetails {
        $roleId = $this->requireRoleIdByCode($roleCode);
        $existing = $this->roles[$roleId];
        $this->roles[$roleId] = [
            ...$existing,
            'isActive' => $isActive,
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildRoleDetails($roleId);
    }

    public function assignPermission(
        RoleCode $roleCode,
        PermissionCode $permissionCode,
        UserId $actorUserId,
    ): void {
        $this->requireRoleIdByCode($roleCode);
        $this->requirePermissionIdByCode($permissionCode);
        $now = $this->timestampUtc();

        foreach ($this->rolePermissionAssignments as $index => $assignment) {
            if (
                $assignment['roleCode']->equals($roleCode)
                && $assignment['permissionCode']->equals($permissionCode)
            ) {
                if ($assignment['isActive']) {
                    return;
                }

                $this->rolePermissionAssignments[$index]['isActive'] = true;
                $this->rolePermissionAssignments[$index]['assignedAtUtc'] = $now;
                $this->touchRole($roleCode);

                return;
            }
        }

        $this->rolePermissionAssignments[] = [
            'roleCode' => $roleCode,
            'permissionCode' => $permissionCode,
            'isActive' => true,
            'assignedAtUtc' => $now,
        ];
        $this->touchRole($roleCode);
    }

    public function removePermission(RoleCode $roleCode, PermissionCode $permissionCode): bool
    {
        $removed = false;

        foreach ($this->rolePermissionAssignments as $index => $assignment) {
            if (
                $assignment['roleCode']->equals($roleCode)
                && $assignment['permissionCode']->equals($permissionCode)
                && $assignment['isActive']
            ) {
                $this->rolePermissionAssignments[$index]['isActive'] = false;
                $removed = true;
            }
        }

        if ($removed) {
            $this->touchRole($roleCode);
        }

        return $removed;
    }

    public function roleHasPermission(RoleCode $roleCode, PermissionCode $permissionCode): bool
    {
        foreach ($this->rolePermissionAssignments as $assignment) {
            if (
                $assignment['roleCode']->equals($roleCode)
                && $assignment['permissionCode']->equals($permissionCode)
                && $assignment['isActive']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PermissionSummary>
     */
    public function listPermissions(?string $search = null, ?bool $isActive = null, ?string $moduleCode = null): array
    {
        $items = [];
        foreach ($this->permissions as $permission) {
            if ($isActive !== null && $permission['isActive'] !== $isActive) {
                continue;
            }

            if ($moduleCode !== null && strcasecmp($permission['moduleCode'], $moduleCode) !== 0) {
                continue;
            }

            if (
                $search !== null
                && !$this->matchesSearch($search, [$permission['permissionCode']->toString(), $permission['permissionName']])
            ) {
                continue;
            }

            $items[] = $this->buildPermissionSummary($permission['id']);
        }

        usort($items, static fn (PermissionSummary $a, PermissionSummary $b): int => strcmp($a->permissionCode, $b->permissionCode));

        return $items;
    }

    public function findPermissionByCode(PermissionCode $permissionCode): ?PermissionSummary
    {
        $permissionId = $this->permissionIdsByCode[$permissionCode->toString()] ?? null;
        if ($permissionId === null) {
            return null;
        }

        return $this->buildPermissionSummary($permissionId);
    }

    public function findPermissionIdByCode(PermissionCode $permissionCode): ?int
    {
        return $this->permissionIdsByCode[$permissionCode->toString()] ?? null;
    }

    public function permissionCodeExists(PermissionCode $permissionCode): bool
    {
        return isset($this->permissionIdsByCode[$permissionCode->toString()]);
    }

    public function isPermissionActive(PermissionCode $permissionCode): bool
    {
        $permissionId = $this->permissionIdsByCode[$permissionCode->toString()] ?? null;
        if ($permissionId === null) {
            return false;
        }

        return $this->permissions[$permissionId]['isActive'];
    }

    public function countActiveRolesUsing(PermissionCode $permissionCode): int
    {
        $count = 0;
        foreach ($this->roles as $role) {
            /** @var RoleCode $roleCode */
            $roleCode = $role['roleCode'];
            if (!$role['isActive']) {
                continue;
            }

            if ($this->roleHasPermission($roleCode, $permissionCode)) {
                ++$count;
            }
        }

        return $count;
    }

    public function countActiveUsersDependingOn(PermissionCode $permissionCode): int
    {
        $count = 0;

        foreach ($this->users as $user) {
            $status = UserStatus::fromString($user['status']);
            if (!$status->isActive()) {
                continue;
            }

            $permissions = $this->calculateEffectivePermissions($user['id']);
            foreach ($permissions as $grant) {
                if ($grant->permissionCode === $permissionCode->toString()) {
                    ++$count;
                    break;
                }
            }
        }

        return $count;
    }

    public function createPermission(
        PermissionCode $permissionCode,
        string $permissionName,
        ?string $description,
        string $moduleCode,
        bool $isActive,
        UserId $actorUserId,
    ): PermissionSummary {
        if ($this->permissionCodeExists($permissionCode)) {
            throw new \RuntimeException(sprintf('Permission code "%s" already exists.', $permissionCode->toString()));
        }

        $now = $this->timestampUtc();
        $permissionId = $this->nextPermissionId++;

        $this->permissions[$permissionId] = [
            'id' => $permissionId,
            'publicId' => $this->uuids->generate()->toString(),
            'permissionCode' => $permissionCode,
            'permissionName' => $permissionName,
            'description' => $description,
            'moduleCode' => $moduleCode,
            'isActive' => $isActive,
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
        ];
        $this->permissionIdsByCode[$permissionCode->toString()] = $permissionId;

        return $this->buildPermissionSummary($permissionId);
    }

    public function updatePermission(
        PermissionCode $permissionCode,
        string $permissionName,
        ?string $description,
        ?bool $isActive,
        UserId $actorUserId,
    ): PermissionSummary {
        $permissionId = $this->requirePermissionIdByCode($permissionCode);
        $existing = $this->permissions[$permissionId];
        $this->permissions[$permissionId] = [
            ...$existing,
            'permissionName' => $permissionName,
            'description' => $description,
            'isActive' => $isActive ?? $existing['isActive'],
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildPermissionSummary($permissionId);
    }

    public function append(UserManagementAuditWrite $entry): UserManagementAuditEntry
    {
        $createdAt = $this->timestampUtc();
        $publicId = $this->uuids->generate()->toString();
        $actorPublicId = $this->publicIdForUserId($entry->actorUserId) ?? 'unknown';
        $targetUserPublicId = $entry->targetUserId !== null
            ? $this->publicIdForUserId($entry->targetUserId)
            : null;
        $targetRoleCode = $entry->targetRoleId !== null
            ? $this->roleCodeForId($entry->targetRoleId)
            : null;
        $targetPermissionCode = $entry->targetPermissionId !== null
            ? $this->permissionCodeForId($entry->targetPermissionId)
            : null;

        $record = new UserManagementAuditEntry(
            publicId: $publicId,
            eventType: $entry->eventType,
            eventSummary: $entry->eventSummary,
            actorPublicId: $actorPublicId,
            targetUserPublicId: $targetUserPublicId,
            targetRoleCode: $targetRoleCode,
            targetPermissionCode: $targetPermissionCode,
            previousValues: $entry->previousValues,
            newValues: $entry->newValues,
            createdAtUtc: $createdAt,
            correlationId: $entry->correlationId,
        );

        $this->auditEntries[] = $record;

        return $record;
    }

    public function list(UserManagementAuditCriteria $criteria): UserManagementAuditListResult
    {
        $filtered = array_values(array_filter(
            $this->auditEntries,
            static function (UserManagementAuditEntry $entry) use ($criteria): bool {
                if ($criteria->targetUserPublicId !== null && $entry->targetUserPublicId !== $criteria->targetUserPublicId) {
                    return false;
                }

                if ($criteria->targetRoleCode !== null && $entry->targetRoleCode !== $criteria->targetRoleCode) {
                    return false;
                }

                if ($criteria->eventType !== null && $entry->eventType !== $criteria->eventType) {
                    return false;
                }

                return true;
            },
        ));

        $total = count($filtered);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $items = array_slice($filtered, $offset, $criteria->pageSize);

        return new UserManagementAuditListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    /**
     * @return list<UserManagementAuditEntry>
     */
    public function listRecentForUser(string $userPublicId, int $limit = 10): array
    {
        $matches = array_values(array_filter(
            $this->auditEntries,
            static fn (UserManagementAuditEntry $entry): bool => $entry->targetUserPublicId === $userPublicId,
        ));

        if ($limit < 1) {
            return [];
        }

        return array_slice(array_reverse($matches), 0, $limit);
    }

    private function buildUserDetails(int $userId): UserDetails
    {
        $user = $this->users[$userId];

        return new UserDetails(
            publicId: $user['publicId'],
            emailAddress: $user['emailAddress'],
            displayName: $user['displayName'],
            status: $user['status'],
            roles: $this->buildUserRoleAssignments($userId),
            effectivePermissions: $this->calculateEffectivePermissions($userId),
            identityProviders: $this->buildIdentityProviderSummaries($userId),
            createdAtUtc: $user['createdAtUtc'],
            updatedAtUtc: $user['updatedAtUtc'],
            lastLoginAtUtc: $user['lastLoginAtUtc'],
        );
    }

    private function buildUserSummary(UserPublicId $publicId): ?UserSummary
    {
        $userId = $this->userIdsByPublicId($publicId);
        if ($userId === null) {
            return null;
        }

        $user = $this->users[$userId];
        $roleCodes = [];
        foreach ($this->buildUserRoleAssignments($userId) as $assignment) {
            if ($assignment->isActive && !$assignment->isExpired) {
                $roleCodes[] = $assignment->roleCode;
            }
        }

        $providers = [];
        foreach ($this->identityProviders as $provider) {
            if ($provider['userId'] === $userId && $provider['isLinked']) {
                $providers[] = $provider['providerCode'];
            }
        }

        sort($roleCodes);
        sort($providers);

        return new UserSummary(
            publicId: $user['publicId'],
            emailAddress: $user['emailAddress'],
            displayName: $user['displayName'],
            status: $user['status'],
            roles: $roleCodes,
            providers: $providers,
            createdAtUtc: $user['createdAtUtc'],
            updatedAtUtc: $user['updatedAtUtc'],
            lastLoginAtUtc: $user['lastLoginAtUtc'],
        );
    }

    /**
     * @return list<UserRoleAssignment>
     */
    private function buildUserRoleAssignments(int $userId): array
    {
        $items = [];

        foreach ($this->userRoleAssignments as $assignment) {
            if ($assignment['userId'] !== $userId || !$assignment['isActive']) {
                continue;
            }

            $roleCode = $assignment['roleCode'];
            $roleId = $this->roleIdsByCode[$roleCode->toString()] ?? null;
            $roleName = $roleId !== null ? $this->roles[$roleId]['roleName'] : $roleCode->toString();
            $expired = $this->isExpiredUtc($assignment['expiresAtUtc']);

            $items[] = new UserRoleAssignment(
                roleCode: $roleCode->toString(),
                roleName: $roleName,
                isActive: true,
                assignedAtUtc: $assignment['assignedAtUtc'],
                expiresAtUtc: $assignment['expiresAtUtc'],
                isExpired: $expired,
            );
        }

        usort($items, static fn (UserRoleAssignment $a, UserRoleAssignment $b): int => strcmp($a->roleCode, $b->roleCode));

        return $items;
    }

    /**
     * @return list<EffectivePermissionGrant>
     */
    private function calculateEffectivePermissions(int $userId): array
    {
        return $this->permissionCalculator->calculate($this->buildPermissionSources($userId));
    }

    /**
     * @return list<RolePermissionSource>
     */
    private function buildPermissionSources(int $userId): array
    {
        $sources = [];

        foreach ($this->userRoleAssignments as $assignment) {
            if ($assignment['userId'] !== $userId || !$assignment['isActive']) {
                continue;
            }

            /** @var RoleCode $roleCode */
            $roleCode = $assignment['roleCode'];
            $roleId = $this->roleIdsByCode[$roleCode->toString()] ?? null;
            if ($roleId === null) {
                continue;
            }

            $role = $this->roles[$roleId];
            $expired = $this->isExpiredUtc($assignment['expiresAtUtc']);

            foreach ($this->rolePermissionAssignments as $rolePermission) {
                if (!$rolePermission['roleCode']->equals($roleCode) || !$rolePermission['isActive']) {
                    continue;
                }

                /** @var PermissionCode $permissionCode */
                $permissionCode = $rolePermission['permissionCode'];
                $permissionId = $this->permissionIdsByCode[$permissionCode->toString()] ?? null;
                if ($permissionId === null) {
                    continue;
                }

                $permission = $this->permissions[$permissionId];

                $sources[] = new RolePermissionSource(
                    roleCode: $roleCode->toString(),
                    permissionCode: $permissionCode->toString(),
                    permissionName: $permission['permissionName'],
                    roleIsActive: $role['isActive'],
                    permissionIsActive: $permission['isActive'],
                    assignmentIsActive: true,
                    isExpired: $expired,
                );
            }
        }

        return $sources;
    }

    /**
     * @return list<IdentityProviderSummary>
     */
    private function buildIdentityProviderSummaries(int $userId): array
    {
        $items = [];

        foreach ($this->identityProviders as $provider) {
            if ($provider['userId'] !== $userId) {
                continue;
            }

            $items[] = new IdentityProviderSummary(
                providerCode: $provider['providerCode'],
                isLinked: $provider['isLinked'],
                isActive: $provider['isActive'],
                lastAuthenticatedAtUtc: $provider['lastAuthenticatedAtUtc'],
                maskedSubject: $this->maskSubject($provider['providerCode'], $provider['subject']),
            );
        }

        usort($items, static fn (IdentityProviderSummary $a, IdentityProviderSummary $b): int => strcmp($a->providerCode, $b->providerCode));

        return $items;
    }

    private function buildRoleDetails(int $roleId): RoleDetails
    {
        $role = $this->roles[$roleId];
        /** @var RoleCode $roleCode */
        $roleCode = $role['roleCode'];

        $permissions = [];
        foreach ($this->rolePermissionAssignments as $assignment) {
            if (!$assignment['roleCode']->equals($roleCode) || !$assignment['isActive']) {
                continue;
            }

            /** @var PermissionCode $permissionCode */
            $permissionCode = $assignment['permissionCode'];
            $permissionId = $this->permissionIdsByCode[$permissionCode->toString()] ?? null;
            if ($permissionId === null) {
                continue;
            }

            $permission = $this->permissions[$permissionId];
            $permissions[] = new RolePermissionAssignment(
                permissionCode: $permissionCode->toString(),
                permissionName: $permission['permissionName'],
                moduleCode: $permission['moduleCode'],
                isActive: $permission['isActive'],
                assignedAtUtc: $assignment['assignedAtUtc'],
            );
        }

        usort($permissions, static fn (RolePermissionAssignment $a, RolePermissionAssignment $b): int => strcmp($a->permissionCode, $b->permissionCode));

        $assignedUsers = [];
        foreach ($this->users as $user) {
            $publicId = UserPublicId::fromString($user['publicId']);
            if ($this->userHasActiveRole($publicId, $roleCode)) {
                $summary = $this->buildUserSummary($publicId);
                if ($summary !== null) {
                    $assignedUsers[] = $summary;
                }
            }
        }

        usort(
            $assignedUsers,
            static fn (UserSummary $a, UserSummary $b): int => strcmp($a->emailAddress, $b->emailAddress),
        );

        return new RoleDetails(
            publicId: $role['publicId'],
            roleCode: $roleCode->toString(),
            roleName: $role['roleName'],
            description: $role['description'],
            isSystemRole: $role['isSystemRole'],
            isActive: $role['isActive'],
            permissions: $permissions,
            assignedUsers: $assignedUsers,
            createdAtUtc: $role['createdAtUtc'],
            updatedAtUtc: $role['updatedAtUtc'],
        );
    }

    private function buildRoleSummary(int $roleId): RoleSummary
    {
        $role = $this->roles[$roleId];
        /** @var RoleCode $roleCode */
        $roleCode = $role['roleCode'];

        $permissionCount = 0;
        foreach ($this->rolePermissionAssignments as $assignment) {
            if ($assignment['roleCode']->equals($roleCode) && $assignment['isActive']) {
                ++$permissionCount;
            }
        }

        $userCount = 0;
        foreach ($this->users as $user) {
            $publicId = UserPublicId::fromString($user['publicId']);
            if ($this->userHasActiveRole($publicId, $roleCode)) {
                ++$userCount;
            }
        }

        return new RoleSummary(
            publicId: $role['publicId'],
            roleCode: $roleCode->toString(),
            roleName: $role['roleName'],
            description: $role['description'],
            isSystemRole: $role['isSystemRole'],
            isActive: $role['isActive'],
            userCount: $userCount,
            permissionCount: $permissionCount,
            createdAtUtc: $role['createdAtUtc'],
            updatedAtUtc: $role['updatedAtUtc'],
        );
    }

    private function buildPermissionSummary(int $permissionId): PermissionSummary
    {
        $permission = $this->permissions[$permissionId];
        /** @var PermissionCode $permissionCode */
        $permissionCode = $permission['permissionCode'];

        $roleCodes = [];
        foreach ($this->rolePermissionAssignments as $assignment) {
            if ($assignment['permissionCode']->equals($permissionCode) && $assignment['isActive']) {
                $roleCodes[] = $assignment['roleCode']->toString();
            }
        }

        sort($roleCodes);

        return new PermissionSummary(
            publicId: $permission['publicId'],
            permissionCode: $permissionCode->toString(),
            permissionName: $permission['permissionName'],
            description: $permission['description'],
            moduleCode: $permission['moduleCode'],
            isActive: $permission['isActive'],
            roleCodes: $roleCodes,
            createdAtUtc: $permission['createdAtUtc'],
            updatedAtUtc: $permission['updatedAtUtc'],
        );
    }

    private function maskSubject(string $providerCode, string $subject): string
    {
        if ($providerCode === ExternalProvider::EMAIL_OTP) {
            return EmailMasker::mask(EmailAddress::fromString($subject));
        }

        if (strlen($subject) <= 2) {
            return '***';
        }

        return $subject[0] . '***' . $subject[strlen($subject) - 1];
    }

    private function matchesUserListCriteria(UserSummary $summary, UserListCriteria $criteria): bool
    {
        $search = $criteria->normalizedSearch();
        if ($search !== null && !$this->matchesSearch($search, [$summary->emailAddress, $summary->displayName])) {
            return false;
        }

        if ($criteria->status !== null && strcasecmp($summary->status, $criteria->status) !== 0) {
            return false;
        }

        if ($criteria->roleCode !== null && !in_array(strtoupper($criteria->roleCode), $summary->roles, true)) {
            return false;
        }

        if ($criteria->providerCode !== null) {
            $normalized = str_replace('-', '_', strtolower($criteria->providerCode));
            if (!in_array($normalized, $summary->providers, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string|null> $fields
     */
    private function matchesSearch(string $search, array $fields): bool
    {
        $needle = strtolower($search);

        foreach ($fields as $field) {
            if ($field !== null && str_contains(strtolower($field), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function compareUserSummaries(UserSummary $a, UserSummary $b, UserListCriteria $criteria): int
    {
        $direction = $criteria->normalizedSortDirection() === UserListCriteria::SORT_ASC ? 1 : -1;

        $value = match ($criteria->sortBy) {
            UserListCriteria::SORT_EMAIL => strcmp($a->emailAddress, $b->emailAddress),
            UserListCriteria::SORT_DISPLAY_NAME => strcmp($a->displayName, $b->displayName),
            UserListCriteria::SORT_STATUS => strcmp($a->status, $b->status),
            UserListCriteria::SORT_UPDATED_AT => strcmp($a->updatedAtUtc, $b->updatedAtUtc),
            UserListCriteria::SORT_LAST_LOGIN_AT => strcmp($a->lastLoginAtUtc ?? '', $b->lastLoginAtUtc ?? ''),
            default => strcmp($a->createdAtUtc, $b->createdAtUtc),
        };

        if ($value === 0) {
            return strcmp($a->publicId, $b->publicId) * $direction;
        }

        return $value * $direction;
    }

    private function userHasLinkedProvider(int $userId, string $providerCode): bool
    {
        foreach ($this->identityProviders as $provider) {
            if ($provider['userId'] === $userId && $provider['providerCode'] === $providerCode && $provider['isLinked']) {
                return true;
            }
        }

        return false;
    }

    private function isExpiredUtc(?string $expiresAtUtc): bool
    {
        if ($expiresAtUtc === null) {
            return false;
        }

        $expiresAt = new DateTimeImmutable($expiresAtUtc, new DateTimeZone('UTC'));

        return $expiresAt <= $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function timestampUtc(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function touchUser(int $userId): void
    {
        $existing = $this->users[$userId];
        $this->users[$userId] = [
            ...$existing,
            'updatedAtUtc' => $this->timestampUtc(),
        ];
    }

    private function touchRole(RoleCode $roleCode): void
    {
        $roleId = $this->roleIdsByCode[$roleCode->toString()] ?? null;
        if ($roleId !== null) {
            $existing = $this->roles[$roleId];
            $this->roles[$roleId] = [
                ...$existing,
                'updatedAtUtc' => $this->timestampUtc(),
            ];
        }
    }

    private function userIdsByPublicId(UserPublicId $publicId): ?int
    {
        foreach ($this->users as $user) {
            if ($user['publicId'] === $publicId->toString()) {
                return $user['id'];
            }
        }

        return null;
    }

    private function requireUserIdByPublicId(UserPublicId $publicId): int
    {
        $userId = $this->userIdsByPublicId($publicId);
        if ($userId === null) {
            throw new \RuntimeException(sprintf('User "%s" not found.', $publicId->toString()));
        }

        return $userId;
    }

    private function requireRoleIdByCode(RoleCode $roleCode): int
    {
        $roleId = $this->roleIdsByCode[$roleCode->toString()] ?? null;
        if ($roleId === null) {
            throw new \RuntimeException(sprintf('Role "%s" not found.', $roleCode->toString()));
        }

        return $roleId;
    }

    private function requirePermissionIdByCode(PermissionCode $permissionCode): int
    {
        $permissionId = $this->permissionIdsByCode[$permissionCode->toString()] ?? null;
        if ($permissionId === null) {
            throw new \RuntimeException(sprintf('Permission "%s" not found.', $permissionCode->toString()));
        }

        return $permissionId;
    }

    private function publicIdForUserId(int $userId): ?string
    {
        return $this->users[$userId]['publicId'] ?? null;
    }

    private function roleCodeForId(int $roleId): ?string
    {
        return isset($this->roles[$roleId]['roleCode'])
            ? $this->roles[$roleId]['roleCode']->toString()
            : null;
    }

    private function permissionCodeForId(int $permissionId): ?string
    {
        return isset($this->permissions[$permissionId]['permissionCode'])
            ? $this->permissions[$permissionId]['permissionCode']->toString()
            : null;
    }
}

final class InMemoryUserManagementUserRepository implements UserManagementRepository
{
    public function __construct(
        private readonly InMemoryUserManagementStore $store,
    ) {
    }

    public function listUsers(UserListCriteria $criteria): UserListResult
    {
        return $this->store->listUsers($criteria);
    }

    public function findDetailsByPublicId(UserPublicId $publicId): ?UserDetails
    {
        return $this->store->findDetailsByPublicId($publicId);
    }

    public function findUserIdByPublicId(UserPublicId $publicId): ?UserId
    {
        return $this->store->findUserIdByPublicId($publicId);
    }

    public function emailExists(EmailAddress $emailAddress, ?UserPublicId $exceptPublicId = null): bool
    {
        return $this->store->emailExists($emailAddress, $exceptPublicId);
    }

    public function createUser(
        EmailAddress $emailAddress,
        string $displayName,
        UserStatus $status,
        array $roleCodes,
        UserId $actorUserId,
    ): UserDetails {
        return $this->store->createUser($emailAddress, $displayName, $status, $roleCodes, $actorUserId);
    }

    public function updateUser(
        UserPublicId $publicId,
        string $displayName,
        ?EmailAddress $emailAddress,
        UserId $actorUserId,
    ): UserDetails {
        return $this->store->updateUser($publicId, $displayName, $emailAddress, $actorUserId);
    }

    public function changeStatus(
        UserPublicId $publicId,
        UserStatus $status,
        UserId $actorUserId,
    ): UserDetails {
        return $this->store->changeStatus($publicId, $status, $actorUserId);
    }

    public function assignRole(
        UserPublicId $publicId,
        RoleCode $roleCode,
        ?DateTimeImmutable $expiresAtUtc,
        UserId $actorUserId,
    ): void {
        $this->store->assignRole($publicId, $roleCode, $expiresAtUtc, $actorUserId);
    }

    public function removeRole(UserPublicId $publicId, RoleCode $roleCode): bool
    {
        return $this->store->removeRole($publicId, $roleCode);
    }

    public function userHasActiveRole(UserPublicId $publicId, RoleCode $roleCode): bool
    {
        return $this->store->userHasActiveRole($publicId, $roleCode);
    }

    public function countActiveUsersWithRole(RoleCode $roleCode): int
    {
        return $this->store->countActiveUsersWithRole($roleCode);
    }

    public function summarizeCounts(): UserManagementSummaryCounts
    {
        return $this->store->summarizeCounts();
    }
}

final class InMemoryUserManagementRoleRepository implements RoleManagementRepository
{
    public function __construct(
        private readonly InMemoryUserManagementStore $store,
    ) {
    }

    public function listRoles(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->listRoles($search, $isActive);
    }

    public function findByCode(RoleCode $roleCode): ?RoleDetails
    {
        return $this->store->findRoleByCode($roleCode);
    }

    public function findRoleIdByCode(RoleCode $roleCode): ?int
    {
        return $this->store->findRoleIdByCode($roleCode);
    }

    public function roleCodeExists(RoleCode $roleCode): bool
    {
        return $this->store->roleCodeExists($roleCode);
    }

    public function isRoleActive(RoleCode $roleCode): bool
    {
        return $this->store->isRoleActive($roleCode);
    }

    public function createRole(
        RoleCode $roleCode,
        string $roleName,
        ?string $description,
        bool $isActive,
        UserId $actorUserId,
    ): RoleDetails {
        return $this->store->createRole($roleCode, $roleName, $description, $isActive, $actorUserId);
    }

    public function updateRole(
        RoleCode $roleCode,
        string $roleName,
        ?string $description,
        UserId $actorUserId,
    ): RoleDetails {
        return $this->store->updateRole($roleCode, $roleName, $description, $actorUserId);
    }

    public function changeStatus(
        RoleCode $roleCode,
        bool $isActive,
        UserId $actorUserId,
    ): RoleDetails {
        return $this->store->changeRoleStatus($roleCode, $isActive, $actorUserId);
    }

    public function assignPermission(
        RoleCode $roleCode,
        PermissionCode $permissionCode,
        UserId $actorUserId,
    ): void {
        $this->store->assignPermission($roleCode, $permissionCode, $actorUserId);
    }

    public function removePermission(RoleCode $roleCode, PermissionCode $permissionCode): bool
    {
        return $this->store->removePermission($roleCode, $permissionCode);
    }

    public function roleHasPermission(RoleCode $roleCode, PermissionCode $permissionCode): bool
    {
        return $this->store->roleHasPermission($roleCode, $permissionCode);
    }
}

final class InMemoryUserManagementPermissionRepository implements PermissionManagementRepository
{
    public function __construct(
        private readonly InMemoryUserManagementStore $store,
    ) {
    }

    public function listPermissions(?string $search = null, ?bool $isActive = null, ?string $moduleCode = null): array
    {
        return $this->store->listPermissions($search, $isActive, $moduleCode);
    }

    public function findByCode(PermissionCode $permissionCode): ?PermissionSummary
    {
        return $this->store->findPermissionByCode($permissionCode);
    }

    public function findPermissionIdByCode(PermissionCode $permissionCode): ?int
    {
        return $this->store->findPermissionIdByCode($permissionCode);
    }

    public function permissionCodeExists(PermissionCode $permissionCode): bool
    {
        return $this->store->permissionCodeExists($permissionCode);
    }

    public function isPermissionActive(PermissionCode $permissionCode): bool
    {
        return $this->store->isPermissionActive($permissionCode);
    }

    public function countActiveRolesUsing(PermissionCode $permissionCode): int
    {
        return $this->store->countActiveRolesUsing($permissionCode);
    }

    public function countActiveUsersDependingOn(PermissionCode $permissionCode): int
    {
        return $this->store->countActiveUsersDependingOn($permissionCode);
    }

    public function createPermission(
        PermissionCode $permissionCode,
        string $permissionName,
        ?string $description,
        string $moduleCode,
        bool $isActive,
        UserId $actorUserId,
    ): PermissionSummary {
        return $this->store->createPermission(
            $permissionCode,
            $permissionName,
            $description,
            $moduleCode,
            $isActive,
            $actorUserId,
        );
    }

    public function updatePermission(
        PermissionCode $permissionCode,
        string $permissionName,
        ?string $description,
        ?bool $isActive,
        UserId $actorUserId,
    ): PermissionSummary {
        return $this->store->updatePermission(
            $permissionCode,
            $permissionName,
            $description,
            $isActive,
            $actorUserId,
        );
    }
}

final class InMemoryUserManagementAuditRepository implements UserManagementAuditRepository
{
    public function __construct(
        private readonly InMemoryUserManagementStore $store,
    ) {
    }

    public function append(UserManagementAuditWrite $entry): UserManagementAuditEntry
    {
        return $this->store->append($entry);
    }

    public function list(UserManagementAuditCriteria $criteria): UserManagementAuditListResult
    {
        return $this->store->list($criteria);
    }

    public function listRecentForUser(string $userPublicId, int $limit = 10): array
    {
        return $this->store->listRecentForUser($userPublicId, $limit);
    }
}
