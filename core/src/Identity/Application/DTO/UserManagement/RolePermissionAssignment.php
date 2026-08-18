<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class RolePermissionAssignment
{
    public function __construct(
        public string $permissionCode,
        public string $permissionName,
        public string $moduleCode,
        public bool $isActive,
        public string $assignedAtUtc,
    ) {
    }
}
