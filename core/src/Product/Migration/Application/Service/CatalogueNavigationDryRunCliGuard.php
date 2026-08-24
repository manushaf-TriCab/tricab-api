<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

final class CatalogueNavigationDryRunCliGuard
{
    public const APPLY_NOT_APPROVED = 'CATALOGUE_NAVIGATION_APPLY_NOT_APPROVED';

    /**
     * @param list<string> $argv
     */
    public static function refuseApply(array $argv): ?string
    {
        foreach (['--apply', '--execute', '--write', '--catalogue-navigation-apply-approved'] as $flag) {
            if (in_array($flag, $argv, true)) {
                return self::APPLY_NOT_APPROVED;
            }
        }

        return null;
    }
}