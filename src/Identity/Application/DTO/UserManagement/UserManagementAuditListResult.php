<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserManagementAuditListResult
{
    /**
     * @param list<UserManagementAuditEntry> $items
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
    ) {
    }
}
