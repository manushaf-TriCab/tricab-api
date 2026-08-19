<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

/**
 * SELECT-only lookup of active TriCab identity roles by RoleCode.
 */
interface TriCabRoleCatalogLookup
{
    /**
     * @return list<array{roleCode: string, roleName: string, isActive: bool}>
     */
    public function activeRoles(): array;
}
