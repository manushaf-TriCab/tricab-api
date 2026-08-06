<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleSummary;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface RoleManagementRepository
{
    /**
     * @return list<RoleSummary>
     */
    public function listRoles(?string $search = null, ?bool $isActive = null): array;

    public function findByCode(RoleCode $roleCode): ?RoleDetails;

    public function findRoleIdByCode(RoleCode $roleCode): ?int;

    public function roleCodeExists(RoleCode $roleCode): bool;

    public function isRoleActive(RoleCode $roleCode): bool;

    public function createRole(
        RoleCode $roleCode,
        string $roleName,
        ?string $description,
        bool $isActive,
        UserId $actorUserId,
    ): RoleDetails;

    public function updateRole(
        RoleCode $roleCode,
        string $roleName,
        ?string $description,
        UserId $actorUserId,
    ): RoleDetails;

    public function changeStatus(
        RoleCode $roleCode,
        bool $isActive,
        UserId $actorUserId,
    ): RoleDetails;

    public function assignPermission(
        RoleCode $roleCode,
        PermissionCode $permissionCode,
        UserId $actorUserId,
    ): void;

    /**
     * Removes (deactivates) a role-permission mapping. Returns false when absent.
     */
    public function removePermission(RoleCode $roleCode, PermissionCode $permissionCode): bool;

    public function roleHasPermission(RoleCode $roleCode, PermissionCode $permissionCode): bool;
}
