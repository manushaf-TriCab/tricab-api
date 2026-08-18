<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\Exception\InactiveRoleAssignment;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class AssignRoleToUserService
{
    public function __construct(
        private readonly UserManagementRepository $users,
        private readonly RoleManagementRepository $roles,
        private readonly UserManagementAuditRecorder $audit,
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $publicId,
        string $roleCode,
        ?DateTimeImmutable $expiresAtUtc = null,
    ): UserDetails {
        $id = UserPublicId::fromString($publicId);
        $role = RoleCode::fromString($roleCode);

        if ($this->users->findDetailsByPublicId($id) === null) {
            throw UserNotFound::forPublicId($id->toString());
        }

        if (!$this->roles->roleCodeExists($role)) {
            throw RoleNotFound::forCode($role->toString());
        }

        if (!$this->roles->isRoleActive($role)) {
            throw InactiveRoleAssignment::forRoleCode($role->toString());
        }

        if (!$this->users->userHasActiveRole($id, $role)) {
            $this->users->assignRole(
                publicId: $id,
                roleCode: $role,
                expiresAtUtc: $expiresAtUtc,
                actorUserId: $this->audit->actorUserId($actor),
            );

            $targetUserId = $this->users->findUserIdByPublicId($id);
            $targetRoleId = $this->roles->findRoleIdByCode($role);

            $this->audit->record(
                actor: $actor,
                eventType: UserManagementEventType::USER_ROLE_ASSIGNED,
                eventSummary: sprintf('Assigned role %s', $role->toString()),
                targetUserId: $targetUserId?->toInt(),
                targetRoleId: $targetRoleId,
                newValues: [
                    'roleCode' => $role->toString(),
                    'expiresAtUtc' => $expiresAtUtc?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
                ],
            );
        }

        $details = $this->users->findDetailsByPublicId($id);
        if ($details === null) {
            throw UserNotFound::forPublicId($id->toString());
        }

        return $details;
    }
}
