<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Application\Exception\PermissionCodeConflict;
use TriCab\Core\Identity\Contract\PermissionManagementRepository;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;

final class CreatePermissionService
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
        string $moduleCode,
        ?string $description = null,
        bool $isActive = true,
    ): PermissionSummary {
        $code = PermissionCode::fromString($permissionCode);
        $name = $this->requireName($permissionName, 'PermissionName');
        $module = $this->requireName($moduleCode, 'ModuleCode');

        if ($this->permissions->permissionCodeExists($code)) {
            throw PermissionCodeConflict::forCode($code->toString());
        }

        $created = $this->permissions->createPermission(
            permissionCode: $code,
            permissionName: $name,
            description: $this->normalizeDescription($description),
            moduleCode: $module,
            isActive: $isActive,
            actorUserId: $this->audit->actorUserId($actor),
        );

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::PERMISSION_CREATED,
            eventSummary: sprintf('Created permission %s', $created->permissionCode),
            targetPermissionId: $this->permissions->findPermissionIdByCode($code),
            newValues: [
                'permissionCode' => $created->permissionCode,
                'permissionName' => $created->permissionName,
                'moduleCode' => $created->moduleCode,
                'isActive' => $created->isActive,
            ],
        );

        return $created;
    }

    private function requireName(string $value, string $type): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw InvalidIdentityValue::for($type, $value);
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
