<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserManagementAuditCriteria
{
    public function __construct(
        public int $page = 1,
        public int $pageSize = 25,
        public ?string $targetUserPublicId = null,
        public ?string $targetRoleCode = null,
        public ?string $eventType = null,
    ) {
        if ($this->page < 1) {
            throw new \InvalidArgumentException('page must be >= 1.');
        }

        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw new \InvalidArgumentException('pageSize must be between 1 and 100.');
        }
    }
}
