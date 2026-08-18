<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;

final class UpdateRoleService
{
    public function __construct(
        private readonly RoleManagementRepository $roles,
        private readonly UserManagementAuditRecorder $audit,
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $roleCode,
        string $roleName,
        ?string $description = null,
    ): RoleDetails {
        $code = RoleCode::fromString($roleCode);
        $existing = $this->roles->findByCode($code);

        if ($existing === null) {
            throw RoleNotFound::forCode($code->toString());
        }

        $name = trim($roleName);
        if ($name === '') {
            throw InvalidIdentityValue::for('RoleName', $roleName);
        }

        $normalizedDescription = $description === null ? null : (trim($description) === '' ? null : trim($description));

        $updated = $this->roles->updateRole(
            roleCode: $code,
            roleName: $name,
            description: $normalizedDescription,
            actorUserId: $this->audit->actorUserId($actor),
        );

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::ROLE_UPDATED,
            eventSummary: sprintf('Updated role %s', $updated->roleCode),
            targetRoleId: $this->roles->findRoleIdByCode($code),
            previousValues: [
                'roleName' => $existing->roleName,
                'description' => $existing->description,
            ],
            newValues: [
                'roleName' => $updated->roleName,
                'description' => $updated->description,
            ],
        );

        return $updated;
    }
}
