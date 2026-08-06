<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Exception\RoleCodeConflict;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;

final class CreateRoleService
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
        bool $isActive = true,
    ): RoleDetails {
        $code = RoleCode::fromString($roleCode);
        $name = $this->requireRoleName($roleName);

        if ($this->roles->roleCodeExists($code)) {
            throw RoleCodeConflict::forCode($code->toString());
        }

        $created = $this->roles->createRole(
            roleCode: $code,
            roleName: $name,
            description: $this->normalizeDescription($description),
            isActive: $isActive,
            actorUserId: $this->audit->actorUserId($actor),
        );

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::ROLE_CREATED,
            eventSummary: sprintf('Created role %s', $created->roleCode),
            targetRoleId: $this->roles->findRoleIdByCode($code),
            newValues: [
                'roleCode' => $created->roleCode,
                'roleName' => $created->roleName,
                'description' => $created->description,
                'isActive' => $created->isActive,
            ],
        );

        return $created;
    }

    private function requireRoleName(string $roleName): string
    {
        $normalized = trim($roleName);
        if ($normalized === '') {
            throw InvalidIdentityValue::for('RoleName', $roleName);
        }

        return $normalized;
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $trimmed = trim($description);

        return $trimmed === '' ? null : $trimmed;
    }
}
