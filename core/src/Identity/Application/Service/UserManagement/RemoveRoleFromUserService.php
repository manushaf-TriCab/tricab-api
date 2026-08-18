<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\Service\AdministratorProtectionPolicy;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class RemoveRoleFromUserService
{
    public function __construct(
        private readonly UserManagementRepository $users,
        private readonly RoleManagementRepository $roles,
        private readonly UserManagementAuditRecorder $audit,
        private readonly AdministratorProtectionPolicy $protection = new AdministratorProtectionPolicy(),
    ) {
    }

    public function execute(ActorContext $actor, string $publicId, string $roleCode): UserDetails
    {
        $id = UserPublicId::fromString($publicId);
        $role = RoleCode::fromString($roleCode);

        if ($this->users->findDetailsByPublicId($id) === null) {
            throw UserNotFound::forPublicId($id->toString());
        }

        if (!$this->roles->roleCodeExists($role)) {
            throw RoleNotFound::forCode($role->toString());
        }

        if ($this->users->userHasActiveRole($id, $role)) {
            $adminCount = $this->users->countActiveUsersWithRole(ProtectedIdentityCatalog::administrator());
            $this->protection->assertAdministratorRoleRemovalAllowed(
                targetPublicId: $id,
                actorPublicId: UserPublicId::fromString($actor->publicId),
                roleCode: $role,
                activeAdministratorCount: $adminCount,
            );

            $removed = $this->users->removeRole($id, $role);
            if ($removed) {
                $targetUserId = $this->users->findUserIdByPublicId($id);
                $targetRoleId = $this->roles->findRoleIdByCode($role);

                $this->audit->record(
                    actor: $actor,
                    eventType: UserManagementEventType::USER_ROLE_REMOVED,
                    eventSummary: sprintf('Removed role %s', $role->toString()),
                    targetUserId: $targetUserId?->toInt(),
                    targetRoleId: $targetRoleId,
                    previousValues: ['roleCode' => $role->toString()],
                );
            }
        }

        $details = $this->users->findDetailsByPublicId($id);
        if ($details === null) {
            throw UserNotFound::forPublicId($id->toString());
        }

        return $details;
    }
}
