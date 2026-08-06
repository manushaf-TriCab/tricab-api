<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditListResult;
use TriCab\Core\Identity\Contract\UserManagementAuditRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class GetUserManagementAuditHistoryService
{
    public function __construct(
        private readonly UserManagementAuditRepository $audit,
    ) {
    }

    public function execute(
        int $page = 1,
        int $pageSize = 25,
        ?string $targetUserPublicId = null,
        ?string $targetRoleCode = null,
        ?string $eventType = null,
    ): UserManagementAuditListResult {
        if ($targetUserPublicId !== null) {
            UserPublicId::fromString($targetUserPublicId);
        }

        if ($eventType !== null) {
            UserManagementEventType::fromString($eventType);
        }

        return $this->audit->list(new UserManagementAuditCriteria(
            page: $page,
            pageSize: $pageSize,
            targetUserPublicId: $targetUserPublicId,
            targetRoleCode: $targetRoleCode,
            eventType: $eventType !== null ? strtoupper(trim($eventType)) : null,
        ));
    }
}
