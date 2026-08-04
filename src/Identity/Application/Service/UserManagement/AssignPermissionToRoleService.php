<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Exception\PermissionNotFound;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Contract\PermissionManagementRepository;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Domain\Exception\InactivePermissionAssignment;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;

final class AssignPermissionToRoleService
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

        if (!$this->permissions->isPermissionActive($permission)) {
            throw InactivePermissionAssignment::forPermissionCode($permission->toString());
        }

        if (!$this->roles->roleHasPermission($role, $permission)) {
            $this->roles->assignPermission(
                roleCode: $role,
                permissionCode: $permission,
                actorUserId: $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                eventType: UserManagementEventType::ROLE_PERMISSION_ASSIGNED,
                eventSummary: sprintf('Assigned permission %s to role %s', $permission->toString(), $role->toString()),
                targetRoleId: $this->roles->findRoleIdByCode($role),
                targetPermissionId: $this->permissions->findPermissionIdByCode($permission),
                newValues: [
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
