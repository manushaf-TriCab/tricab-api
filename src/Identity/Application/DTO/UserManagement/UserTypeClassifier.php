<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;

/**
 * Derives a coarse user-type classification from active role codes for
 * admin list/filter UX. Not an authorization boundary.
 */
final class UserTypeClassifier
{
    public const INTERNAL = 'internal';
    public const CUSTOMER = 'customer';
    public const GUEST = 'guest';

    /**
     * @param list<string> $roleCodes
     */
    public static function fromRoleCodes(array $roleCodes): string
    {
        $normalized = array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $roleCodes,
        );

        foreach ($normalized as $code) {
            if ($code === ProtectedIdentityCatalog::ROLE_CUSTOMER_USER
                || $code === ProtectedIdentityCatalog::ROLE_CUSTOMER_ADMIN
            ) {
                return self::CUSTOMER;
            }
        }

        foreach ($normalized as $code) {
            if ($code === ProtectedIdentityCatalog::ROLE_GUEST) {
                return self::GUEST;
            }
        }

        return self::INTERNAL;
    }

    public static function isValid(string $userType): bool
    {
        return in_array($userType, [self::INTERNAL, self::CUSTOMER, self::GUEST], true);
    }

    /**
     * @param list<string> $roleCodes
     */
    public static function matches(array $roleCodes, string $userType): bool
    {
        return self::fromRoleCodes($roleCodes) === $userType;
    }

    public static function isCustomerFacingRoleCode(string $roleCode): bool
    {
        return ProtectedIdentityCatalog::isCustomerFacingRole(RoleCode::fromString($roleCode));
    }
}
