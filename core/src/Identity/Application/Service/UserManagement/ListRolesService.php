<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\RoleSummary;
use TriCab\Core\Identity\Contract\RoleManagementRepository;

final class ListRolesService
{
    public function __construct(
        private readonly RoleManagementRepository $roles,
    ) {
    }

    /**
     * @return list<RoleSummary>
     */
    public function execute(?string $search = null, ?bool $isActive = null): array
    {
        return $this->roles->listRoles($search, $isActive);
    }
}
