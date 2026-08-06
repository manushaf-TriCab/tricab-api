<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListResult;
use TriCab\Core\Identity\Contract\UserManagementRepository;

final class ListUsersService
{
    public function __construct(
        private readonly UserManagementRepository $users,
    ) {
    }

    public function execute(UserListCriteria $criteria): UserListResult
    {
        return $this->users->listUsers($criteria);
    }
}
