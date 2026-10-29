<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

/**
 * Decides whether an identity may act as TriCab staff: global administration,
 * staff price-as and staff-only response fields.
 *
 * Staff is proven by role, never by permission. Every employee holds EMPLOYEE
 * (ADMINISTRATOR for platform administrators) and specialist roles are added
 * on top, so a specialist or custom role on its own, ACCOUNT.LOGIN, or any
 * other permission is not proof of staff identity.
 *
 * A customer-oriented role disqualifies the identity whatever else it holds,
 * so a dual-role account fails closed instead of silently keeping staff reach.
 * GUEST (a pre-start onboarding account) never makes an identity staff, but it
 * carries nothing beyond sign-in and self-service onboarding, so an employee
 * whose GUEST role has not been removed yet is not disqualified by it.
 */
final class StaffEligibilityPolicy
{
    public const ELIGIBLE = 'ELIGIBLE';
    public const CUSTOMER_ACTOR = 'CUSTOMER_ACTOR';
    public const NOT_STAFF = 'NOT_STAFF';

    public const ROLE_CUSTOMER_APPLICANT = 'CUSTOMER_APPLICANT';
    public const ROLE_NEWBIE = 'NEWBIE';

    /** @var list<string> */
    public const STAFF_ROLES = [
        ProtectedIdentityCatalog::ROLE_EMPLOYEE,
        ProtectedIdentityCatalog::ROLE_ADMINISTRATOR,
    ];

    /** @var list<string> */
    public const CUSTOMER_ORIENTED_ROLES = [
        ProtectedIdentityCatalog::ROLE_CUSTOMER_USER,
        ProtectedIdentityCatalog::ROLE_CUSTOMER_ADMIN,
        self::ROLE_CUSTOMER_APPLICANT,
        self::ROLE_NEWBIE,
    ];

    /**
     * Role-code prefixes reserved for customer-facing roles, so a customer
     * role added later is caught without a code change.
     *
     * @var list<string>
     */
    private const CUSTOMER_ORIENTED_PREFIXES = ['CUSTOMER_', 'PORTAL_'];

    /**
     * Staff modules whose role codes happen to start with a customer prefix.
     * CUSTOMER_CONTACT_* is the staff customer-visit report module.
     *
     * @var list<string>
     */
    private const STAFF_MODULE_PREFIXES = ['CUSTOMER_CONTACT_'];

    /**
     * @param iterable<string> $roleCodes
     */
    public static function evaluate(iterable $roleCodes): string
    {
        $hasStaffRole = false;
        foreach ($roleCodes as $roleCode) {
            $normalized = self::normalize($roleCode);
            if (self::isCustomerOrientedRole($normalized)) {
                return self::CUSTOMER_ACTOR;
            }
            if (in_array($normalized, self::STAFF_ROLES, true)) {
                $hasStaffRole = true;
            }
        }

        return $hasStaffRole ? self::ELIGIBLE : self::NOT_STAFF;
    }

    /**
     * @param iterable<string> $roleCodes
     */
    public static function isEligible(iterable $roleCodes): bool
    {
        return self::evaluate($roleCodes) === self::ELIGIBLE;
    }

    /**
     * @param iterable<string> $roleCodes
     */
    public static function hasCustomerOrientedRole(iterable $roleCodes): bool
    {
        foreach ($roleCodes as $roleCode) {
            if (self::isCustomerOrientedRole($roleCode)) {
                return true;
            }
        }

        return false;
    }

    public static function isCustomerOrientedRole(string $roleCode): bool
    {
        $normalized = self::normalize($roleCode);
        if (in_array($normalized, self::CUSTOMER_ORIENTED_ROLES, true)) {
            return true;
        }

        foreach (self::STAFF_MODULE_PREFIXES as $staffPrefix) {
            if (str_starts_with($normalized, $staffPrefix)) {
                return false;
            }
        }

        foreach (self::CUSTOMER_ORIENTED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $roleCode): string
    {
        return strtoupper(trim($roleCode));
    }
}
