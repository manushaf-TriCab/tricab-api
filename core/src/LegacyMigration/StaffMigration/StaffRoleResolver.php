<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;

/**
 * Resolves effective TriCab role assignment from legacy WordPress role combinations.
 */
final class StaffRoleResolver
{
    public function __construct(
        private readonly LegacyStaffRoleMappingConfig $mappingConfig = new LegacyStaffRoleMappingConfig(),
    ) {
    }

    /**
     * @param list<string> $legacyRoles
     * @param list<string> $availableTriCabRoleCodes
     * @return array{
     *   proposedRoleCodes: list<string>,
     *   proposedRoleLabel: string,
     *   confidence: string,
     *   resolved: bool,
     *   isAdministratorCandidate: bool,
     *   unknownRoles: list<string>,
     *   roleCombination: string,
     *   issues: list<string>
     * }
     */
    public function resolve(array $legacyRoles, array $availableTriCabRoleCodes): array
    {
        $normalized = array_values(array_unique(array_map(static fn (string $r): string => strtolower(trim($r)), $legacyRoles)));
        $normalized = array_values(array_filter($normalized, static fn (string $r): bool => $r !== ''));
        sort($normalized);

        $issues = [];
        $unknownRoles = [];
        $customerRoles = [];
        foreach ($normalized as $role) {
            if ($this->mappingConfig->isCustomerOnlyRole($role)) {
                $customerRoles[] = $role;
            } elseif (!$this->mappingConfig->isMappedLegacyRole($role)) {
                $unknownRoles[] = $role;
            }
        }

        if ($customerRoles !== [] && $unknownRoles === [] && !in_array('administrator', $normalized, true)
            && !in_array('staff', $normalized, true) && !in_array('sales', $normalized, true)) {
            return [
                'proposedRoleCodes' => [],
                'proposedRoleLabel' => 'NONE',
                'confidence' => 'NONE',
                'resolved' => false,
                'isAdministratorCandidate' => false,
                'unknownRoles' => $customerRoles,
                'roleCombination' => implode(' + ', $normalized),
                'issues' => ['CUSTOMER_ROLE_NOT_STAFF'],
            ];
        }

        if ($unknownRoles !== []) {
            return [
                'proposedRoleCodes' => [],
                'proposedRoleLabel' => 'UNKNOWN',
                'confidence' => 'NONE',
                'resolved' => false,
                'isAdministratorCandidate' => in_array('administrator', $normalized, true),
                'unknownRoles' => $unknownRoles,
                'roleCombination' => implode(' + ', $normalized),
                'issues' => ['UNKNOWN_LEGACY_ROLE'],
            ];
        }

        $isAdmin = in_array('administrator', $normalized, true);
        if ($isAdmin) {
            $proposed = [ProtectedIdentityCatalog::ROLE_ADMINISTRATOR];
            $confidence = 'HIGH';
            if (count($normalized) > 1) {
                $issues[] = 'MULTIPLE_LEGACY_ROLES_WITH_ADMINISTRATOR';
            }
        } elseif (in_array('staff', $normalized, true) || in_array('sales', $normalized, true) || in_array('tricab', $normalized, true)) {
            $proposed = [ProtectedIdentityCatalog::ROLE_EMPLOYEE];
            $confidence = in_array('staff', $normalized, true) || in_array('sales', $normalized, true) ? 'HIGH' : 'MEDIUM';
        } elseif (in_array('subscriber', $normalized, true)) {
            $proposed = [ProtectedIdentityCatalog::ROLE_EMPLOYEE];
            $confidence = 'LOW';
            $issues[] = 'SUBSCRIBER_ROLE_LOW_CONFIDENCE';
        } else {
            return [
                'proposedRoleCodes' => [],
                'proposedRoleLabel' => 'UNKNOWN',
                'confidence' => 'NONE',
                'resolved' => false,
                'isAdministratorCandidate' => false,
                'unknownRoles' => [],
                'roleCombination' => implode(' + ', $normalized) ?: '(none)',
                'issues' => ['NO_MAPPABLE_LEGACY_ROLE'],
            ];
        }

        foreach ($proposed as $code) {
            if (!in_array($code, $availableTriCabRoleCodes, true)) {
                return [
                    'proposedRoleCodes' => [],
                    'proposedRoleLabel' => 'UNAVAILABLE',
                    'confidence' => 'NONE',
                    'resolved' => false,
                    'isAdministratorCandidate' => $isAdmin,
                    'unknownRoles' => [],
                    'roleCombination' => implode(' + ', $normalized),
                    'issues' => ['TRICAB_ROLE_NOT_AVAILABLE:' . $code],
                ];
            }
        }

        return [
            'proposedRoleCodes' => $proposed,
            'proposedRoleLabel' => implode(', ', $proposed),
            'confidence' => $confidence,
            'resolved' => $issues === [],
            'isAdministratorCandidate' => $isAdmin,
            'unknownRoles' => [],
            'roleCombination' => implode(' + ', $normalized) ?: '(none)',
            'issues' => $issues,
        ];
    }
}
