<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class EffectivePermissionGrant
{
    /**
     * @param list<string> $grantedByRoleCodes
     */
    public function __construct(
        public string $permissionCode,
        public string $permissionName,
        public array $grantedByRoleCodes,
    ) {
    }
}
