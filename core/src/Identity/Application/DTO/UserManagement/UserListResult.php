<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserListResult
{
    /**
     * @param list<UserSummary> $items
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
        public UserManagementSummaryCounts $counts,
    ) {
    }
}
