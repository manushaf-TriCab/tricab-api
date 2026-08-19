<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffEntraLink;

/** Helpers for @tricab.com corporate email eligibility. */
final class StaffEntraCorporateEmail
{
    public const DOMAIN = '@tricab.com';

    public static function isCorporate(string $normalizedEmail): bool
    {
        return str_ends_with(strtolower(trim($normalizedEmail)), self::DOMAIN);
    }

    public static function localPart(string $normalizedEmail): string
    {
        $email = strtolower(trim($normalizedEmail));
        $at = strpos($email, '@');

        return $at === false ? $email : substr($email, 0, $at);
    }
}
