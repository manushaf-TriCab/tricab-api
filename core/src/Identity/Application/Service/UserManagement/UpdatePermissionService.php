<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Application\Exception\PermissionNotFound;
use TriCab\Core\Identity\Contract\PermissionManagementRepository;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Exception\SystemPermissionProtected;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;

final class UpdatePermissionService
{
    public function __construct(
        private readonly PermissionManagementRepository $permissions,
        private readonly UserManagementAuditRecorder $audit,
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $permissionCode,
        string $permissionName,
        ?string $description = null,
        ?bool $isActive = null,
    ): PermissionSummary {
        $code = PermissionCode::fromString($permissionCode);
        $existing = $this->permissions->findByCode($code);

        if ($existing === null) {
            throw PermissionNotFound::forCode($code->toString());
        }

        $name = trim($permissionName);
        if ($name === '') {
            throw InvalidIdentityValue::for('PermissionName', $permissionName);
        }

        if (
            $isActive === false
            && ProtectedIdentityCatalog::isSystemProtectedPermission($code)
            && $this->permissions->countActiveUsersDependingOn($code) > 0
        ) {
            throw SystemPermissionProtected::cannotDeactivateWhileInUse($code->toString());
        }

        $normalizedDescription = $description === null ? null : (trim($description) === '' ? null : trim($description));

        $updated = $this->permissions->updatePermission(
            permissionCode: $code,
            permissionName: $name,
            description: $normalizedDescription,
            isActive: $isActive,
            actorUserId: $this->audit->actorUserId($actor),
        );

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::PERMISSION_UPDATED,
            eventSummary: sprintf('Updated permission %s', $updated->permissionCode),
            targetPermissionId: $this->permissions->findPermissionIdByCode($code),
            previousValues: [
                'permissionName' => $existing->permissionName,
                'description' => $existing->description,
                'isActive' => $existing->isActive,
            ],
            newValues: [
                'permissionName' => $updated->permissionName,
                'description' => $updated->description,
                'isActive' => $updated->isActive,
            ],
        );

        return $updated;
    }
}
