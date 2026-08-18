<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class RoleDetails
{
    /**
     * @param list<RolePermissionAssignment> $permissions
     * @param list<UserSummary> $assignedUsers
     */
    public function __construct(
        public string $publicId,
        public string $roleCode,
        public string $roleName,
        public ?string $description,
        public bool $isSystemRole,
        public bool $isActive,
        public array $permissions,
        public array $assignedUsers,
        public string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
