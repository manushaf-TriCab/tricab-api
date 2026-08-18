<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Exception\PermissionNotFound;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Contract\PermissionManagementRepository;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;

final class RemovePermissionFromRoleService
{
    public function __construct(
        private readonly RoleManagementRepository $roles,
        private readonly PermissionManagementRepository $permissions,
        private readonly UserManagementAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, string $roleCode, string $permissionCode): RoleDetails
    {
        $role = RoleCode::fromString($roleCode);
        $permission = PermissionCode::fromString($permissionCode);

        if (!$this->roles->roleCodeExists($role)) {
            throw RoleNotFound::forCode($role->toString());
        }

        if (!$this->permissions->permissionCodeExists($permission)) {
            throw PermissionNotFound::forCode($permission->toString());
        }

        if ($this->roles->removePermission($role, $permission)) {
            $this->audit->record(
                actor: $actor,
                eventType: UserManagementEventType::ROLE_PERMISSION_REMOVED,
                eventSummary: sprintf('Removed permission %s from role %s', $permission->toString(), $role->toString()),
                targetRoleId: $this->roles->findRoleIdByCode($role),
                targetPermissionId: $this->permissions->findPermissionIdByCode($permission),
                previousValues: [
                    'roleCode' => $role->toString(),
                    'permissionCode' => $permission->toString(),
                ],
            );
        }

        $details = $this->roles->findByCode($role);
        if ($details === null) {
            throw RoleNotFound::forCode($role->toString());
        }

        return $details;
    }
}
