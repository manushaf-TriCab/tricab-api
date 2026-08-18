<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserRoleAssignment
{
    public function __construct(
        public string $roleCode,
        public string $roleName,
        public bool $isActive,
        public string $assignedAtUtc,
        public ?string $expiresAtUtc = null,
        public bool $isExpired = false,
    ) {
    }
}
