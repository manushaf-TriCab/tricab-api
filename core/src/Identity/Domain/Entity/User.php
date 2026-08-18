<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class User
{
    private readonly string $displayName;

    /** @var list<RoleCode> */
    private readonly array $roles;

    /** @var list<PermissionCode> */
    private readonly array $permissions;

    /**
     * @param list<RoleCode> $roles
     * @param list<PermissionCode> $permissions
     */
    public function __construct(
        private readonly UserId $id,
        private readonly UserPublicId $publicId,
        private readonly EmailAddress $emailAddress,
        string $displayName,
        private readonly UserStatus $status,
        array $roles = [],
        array $permissions = [],
    ) {
        $normalizedDisplayName = trim($displayName);
        if ($normalizedDisplayName === '') {
            throw InvalidIdentityValue::for('DisplayName', $displayName);
        }

        $this->displayName = $normalizedDisplayName;
        $this->roles = $this->uniqueRoles($roles);
        $this->permissions = $this->uniquePermissions($permissions);
    }

    public function id(): UserId
    {
        return $this->id;
    }

    public function publicId(): UserPublicId
    {
        return $this->publicId;
    }

    public function emailAddress(): EmailAddress
    {
        return $this->emailAddress;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    /** @return list<RoleCode> */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return list<PermissionCode> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function isAuthenticationAllowed(): bool
    {
        return $this->status->allowsAuthentication();
    }

    public function assertAuthenticationAllowed(): void
    {
        if (!$this->isAuthenticationAllowed()) {
            throw AuthenticationNotAllowed::forStatus($this->status);
        }
    }

    public function hasRole(RoleCode $roleCode): bool
    {
        foreach ($this->roles as $role) {
            if ($role->equals($roleCode)) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(PermissionCode $permissionCode): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->equals($permissionCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<RoleCode> $roles
     * @return list<RoleCode>
     */
    private function uniqueRoles(array $roles): array
    {
        $unique = [];

        foreach ($roles as $role) {
            foreach ($unique as $existing) {
                if ($existing->equals($role)) {
                    continue 2;
                }
            }

            $unique[] = $role;
        }

        return $unique;
    }

    /**
     * @param list<PermissionCode> $permissions
     * @return list<PermissionCode>
     */
    private function uniquePermissions(array $permissions): array
    {
        $unique = [];

        foreach ($permissions as $permission) {
            foreach ($unique as $existing) {
                if ($existing->equals($permission)) {
                    continue 2;
                }
            }

            $unique[] = $permission;
        }

        return $unique;
    }
}
