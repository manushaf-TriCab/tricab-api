<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface PermissionManagementRepository
{
    /**
     * @return list<PermissionSummary>
     */
    public function listPermissions(?string $search = null, ?bool $isActive = null, ?string $moduleCode = null): array;

    public function findByCode(PermissionCode $permissionCode): ?PermissionSummary;

    public function findPermissionIdByCode(PermissionCode $permissionCode): ?int;

    public function permissionCodeExists(PermissionCode $permissionCode): bool;

    public function isPermissionActive(PermissionCode $permissionCode): bool;

    public function countActiveRolesUsing(PermissionCode $permissionCode): int;

    public function countActiveUsersDependingOn(PermissionCode $permissionCode): int;

    public function createPermission(
        PermissionCode $permissionCode,
        string $permissionName,
        ?string $description,
        string $moduleCode,
        bool $isActive,
        UserId $actorUserId,
    ): PermissionSummary;

    public function updatePermission(
        PermissionCode $permissionCode,
        string $permissionName,
        ?string $description,
        ?bool $isActive,
        UserId $actorUserId,
    ): PermissionSummary;
}
