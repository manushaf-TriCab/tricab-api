<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;

final class GetRoleDetailsService
{
    public function __construct(
        private readonly RoleManagementRepository $roles,
    ) {
    }

    public function execute(string $roleCode): RoleDetails
    {
        $code = RoleCode::fromString($roleCode);
        $details = $this->roles->findByCode($code);

        if ($details === null) {
            throw RoleNotFound::forCode($code->toString());
        }

        return $details;
    }
}
