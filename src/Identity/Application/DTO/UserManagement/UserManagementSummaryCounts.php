<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserManagementSummaryCounts
{
    public function __construct(
        public int $totalUsers,
        public int $activeUsers,
        public int $suspendedUsers,
        public int $guestUsers,
        public int $microsoftUsers,
        public int $administrators,
    ) {
    }
}
