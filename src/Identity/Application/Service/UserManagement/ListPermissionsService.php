<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Contract\PermissionManagementRepository;

final class ListPermissionsService
{
    public function __construct(
        private readonly PermissionManagementRepository $permissions,
    ) {
    }

    /**
     * @return list<PermissionSummary>
     */
    public function execute(?string $search = null, ?bool $isActive = null, ?string $moduleCode = null): array
    {
        return $this->permissions->listPermissions($search, $isActive, $moduleCode);
    }
}
