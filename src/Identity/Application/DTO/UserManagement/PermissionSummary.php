<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class PermissionSummary
{
    /**
     * @param list<string> $roleCodes
     */
    public function __construct(
        public string $publicId,
        public string $permissionCode,
        public string $permissionName,
        public ?string $description,
        public string $moduleCode,
        public bool $isActive,
        public array $roleCodes,
        public string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
