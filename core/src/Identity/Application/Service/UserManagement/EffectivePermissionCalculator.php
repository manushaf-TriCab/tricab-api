<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\EffectivePermissionGrant;
use TriCab\Core\Identity\Application\DTO\UserManagement\RolePermissionSource;

/**
 * Derives effective permissions from role grants, excluding expired assignments.
 */
final class EffectivePermissionCalculator
{
    /**
     * @param list<RolePermissionSource> $sources
     * @return list<EffectivePermissionGrant>
     */
    public function calculate(array $sources): array
    {
        /** @var array<string, EffectivePermissionGrant> $byPermission */
        $byPermission = [];

        foreach ($sources as $source) {
            if ($source->isExpired || !$source->roleIsActive || !$source->permissionIsActive || !$source->assignmentIsActive) {
                continue;
            }

            $permissionCode = $source->permissionCode;
            $existing = $byPermission[$permissionCode] ?? null;
            $grantedBy = $existing !== null ? $existing->grantedByRoleCodes : [];

            if (!in_array($source->roleCode, $grantedBy, true)) {
                $grantedBy[] = $source->roleCode;
            }

            sort($grantedBy);

            $byPermission[$permissionCode] = new EffectivePermissionGrant(
                permissionCode: $permissionCode,
                permissionName: $source->permissionName,
                grantedByRoleCodes: $grantedBy,
            );
        }

        ksort($byPermission);

        return array_values($byPermission);
    }
}
