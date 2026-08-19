<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\TriCabRoleCatalogLookup;

final class NullTriCabRoleCatalogLookup implements TriCabRoleCatalogLookup
{
    public function activeRoles(): array
    {
        return [
            ['roleCode' => 'ADMINISTRATOR', 'roleName' => 'Administrator', 'isActive' => true],
            ['roleCode' => 'EMPLOYEE', 'roleName' => 'Employee', 'isActive' => true],
        ];
    }
}
