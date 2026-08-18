<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;

/**
 * Canonical protected identity codes. Never hard-code numeric RoleId / PermissionId.
 */
final class ProtectedIdentityCatalog
{
    public const ROLE_EMPLOYEE = 'EMPLOYEE';
    public const ROLE_GUEST = 'GUEST';
    public const ROLE_ADMINISTRATOR = 'ADMINISTRATOR';
    public const ROLE_CUSTOMER_USER = 'CUSTOMER_USER';
    public const ROLE_CUSTOMER_ADMIN = 'CUSTOMER_ADMIN';
    public const PERMISSION_ACCOUNT_LOGIN = 'ACCOUNT.LOGIN';

    public static function employee(): RoleCode
    {
        return RoleCode::fromString(self::ROLE_EMPLOYEE);
    }

    public static function guest(): RoleCode
    {
        return RoleCode::fromString(self::ROLE_GUEST);
    }

    public static function administrator(): RoleCode
    {
        return RoleCode::fromString(self::ROLE_ADMINISTRATOR);
    }

    public static function customerUser(): RoleCode
    {
        return RoleCode::fromString(self::ROLE_CUSTOMER_USER);
    }

    public static function customerAdmin(): RoleCode
    {
        return RoleCode::fromString(self::ROLE_CUSTOMER_ADMIN);
    }

    public static function accountLogin(): PermissionCode
    {
        return PermissionCode::fromString(self::PERMISSION_ACCOUNT_LOGIN);
    }

    public static function isSystemProtectedRole(RoleCode $roleCode): bool
    {
        return $roleCode->equals(self::employee())
            || $roleCode->equals(self::guest())
            || $roleCode->equals(self::administrator())
            || $roleCode->equals(self::customerUser())
            || $roleCode->equals(self::customerAdmin());
    }

    public static function isRoleCodeRenameForbidden(RoleCode $roleCode): bool
    {
        return $roleCode->equals(self::employee())
            || $roleCode->equals(self::guest())
            || $roleCode->equals(self::customerUser())
            || $roleCode->equals(self::customerAdmin());
    }

    public static function isSystemProtectedPermission(PermissionCode $permissionCode): bool
    {
        return $permissionCode->equals(self::accountLogin());
    }

    public static function isAdministratorRole(RoleCode $roleCode): bool
    {
        return $roleCode->equals(self::administrator());
    }

    public static function isCustomerFacingRole(RoleCode $roleCode): bool
    {
        return $roleCode->equals(self::customerUser())
            || $roleCode->equals(self::customerAdmin());
    }
}
