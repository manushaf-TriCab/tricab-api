<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditEntry;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditListResult;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditWrite;

interface UserManagementAuditRepository
{
    public function append(UserManagementAuditWrite $entry): UserManagementAuditEntry;

    public function list(UserManagementAuditCriteria $criteria): UserManagementAuditListResult;

    /**
     * @return list<UserManagementAuditEntry>
     */
    public function listRecentForUser(string $userPublicId, int $limit = 10): array;
}
