<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;

/**
 * Explicit legacy WordPress → TriCab role mapping configuration.
 * Uses actual TriCab RoleCode values, never numeric RoleIds.
 */
final class LegacyStaffRoleMappingConfig
{
    /** @var array<string, array{roleCode: string, confidence: string}> */
    private const DIRECT_MAPPINGS = [
        'administrator' => [
            'roleCode' => ProtectedIdentityCatalog::ROLE_ADMINISTRATOR,
            'confidence' => 'HIGH',
        ],
        'staff' => [
            'roleCode' => ProtectedIdentityCatalog::ROLE_EMPLOYEE,
            'confidence' => 'HIGH',
        ],
        'sales' => [
            'roleCode' => ProtectedIdentityCatalog::ROLE_EMPLOYEE,
            'confidence' => 'HIGH',
        ],
        'tricab' => [
            'roleCode' => ProtectedIdentityCatalog::ROLE_EMPLOYEE,
            'confidence' => 'MEDIUM',
        ],
        'subscriber' => [
            'roleCode' => ProtectedIdentityCatalog::ROLE_EMPLOYEE,
            'confidence' => 'LOW',
        ],
    ];

    /** @var list<string> */
    private const CUSTOMER_ONLY_ROLES = StaffMigrationScope::EXCLUDED_CUSTOMER_ROLES;

    /**
     * @return array<string, array{roleCode: string, confidence: string}>
     */
    public function directMappings(): array
    {
        return self::DIRECT_MAPPINGS;
    }

    /** @return list<string> */
    public function customerOnlyRoles(): array
    {
        return self::CUSTOMER_ONLY_ROLES;
    }

    public function isMappedLegacyRole(string $legacyRole): bool
    {
        return isset(self::DIRECT_MAPPINGS[strtolower($legacyRole)]);
    }

    public function isCustomerOnlyRole(string $legacyRole): bool
    {
        return in_array(strtolower($legacyRole), self::CUSTOMER_ONLY_ROLES, true);
    }
}
