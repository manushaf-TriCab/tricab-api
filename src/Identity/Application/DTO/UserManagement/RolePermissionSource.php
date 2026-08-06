<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class RolePermissionSource
{
    public function __construct(
        public string $roleCode,
        public string $permissionCode,
        public string $permissionName,
        public bool $roleIsActive,
        public bool $permissionIsActive,
        public bool $assignmentIsActive,
        public bool $isExpired,
    ) {
    }
}
