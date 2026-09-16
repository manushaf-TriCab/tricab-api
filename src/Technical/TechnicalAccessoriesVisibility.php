<?php

declare(strict_types=1);

namespace TriCab\Api\Technical;

use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;

/**
 * Authoritative Technical Accessories visibility.
 *
 * Legacy: is_mkt('SW[0-9]') OR is_tricab()
 * New:    linked customer MarketCode matches /^SW[0-9]$/ OR staff role EMPLOYEE|ADMINISTRATOR
 */
final class TechnicalAccessoriesVisibility
{
    public const SECTION_ID = 'accessories';

    public const PRODUCT_CODING_MODE = 'accessories';

    /** @var list<string> */
    public const ACCESSORY_DOCUMENT_CATEGORIES = [
        'accessory-certs',
        'accessory-warranty',
        'installation-guides',
    ];

    public const VISIBILITY_POLICY = 'staff_or_sw_digit_market';

    /**
     * @param list<string> $roleCodes
     * @param list<string> $marketCodes
     */
    public static function allows(array $roleCodes, array $marketCodes): bool
    {
        if (self::isStaff($roleCodes)) {
            return true;
        }

        foreach ($marketCodes as $marketCode) {
            if (self::isSwDigitMarket($marketCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $roleCodes
     */
    public static function isStaff(array $roleCodes): bool
    {
        foreach ($roleCodes as $roleCode) {
            $normalized = strtoupper(trim((string) $roleCode));
            if ($normalized === ProtectedIdentityCatalog::ROLE_EMPLOYEE
                || $normalized === ProtectedIdentityCatalog::ROLE_ADMINISTRATOR
            ) {
                return true;
            }
        }

        return false;
    }

    public static function isSwDigitMarket(string $marketCode): bool
    {
        return preg_match('/^SW[0-9]$/i', trim($marketCode)) === 1;
    }

    public static function isAccessoryDocumentCategory(string $category): bool
    {
        $normalized = strtolower(trim($category));

        return in_array($normalized, self::ACCESSORY_DOCUMENT_CATEGORIES, true);
    }
}
