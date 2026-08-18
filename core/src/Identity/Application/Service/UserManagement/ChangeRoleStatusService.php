<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Domain\Exception\SystemRoleProtected;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;

final class ChangeRoleStatusService
{
    public function __construct(
        private readonly RoleManagementRepository $roles,
        private readonly UserManagementAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, string $roleCode, bool $isActive): RoleDetails
    {
        $code = RoleCode::fromString($roleCode);
        $existing = $this->roles->findByCode($code);

        if ($existing === null) {
            throw RoleNotFound::forCode($code->toString());
        }

        if (!$isActive && ProtectedIdentityCatalog::isSystemProtectedRole($code)) {
            throw SystemRoleProtected::cannotDeactivate($code->toString());
        }

        $updated = $this->roles->changeStatus(
            roleCode: $code,
            isActive: $isActive,
            actorUserId: $this->audit->actorUserId($actor),
        );

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::ROLE_STATUS_CHANGED,
            eventSummary: sprintf('Changed role %s active=%s', $updated->roleCode, $updated->isActive ? 'true' : 'false'),
            targetRoleId: $this->roles->findRoleIdByCode($code),
            previousValues: ['isActive' => $existing->isActive],
            newValues: ['isActive' => $updated->isActive],
        );

        return $updated;
    }
}
