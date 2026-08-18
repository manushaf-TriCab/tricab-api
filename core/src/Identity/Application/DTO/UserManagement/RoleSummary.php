<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class RoleSummary
{
    public function __construct(
        public string $publicId,
        public string $roleCode,
        public string $roleName,
        public ?string $description,
        public bool $isSystemRole,
        public bool $isActive,
        public int $userCount,
        public int $permissionCount,
        public string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
