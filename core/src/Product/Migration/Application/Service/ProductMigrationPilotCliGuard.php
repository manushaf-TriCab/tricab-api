<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.8 — Single accessory/DV product migration pilot (dry-run only).
 */
final class ProductMigrationPilotCliGuard
{
    public const APPLY_NOT_APPROVED = 'PILOT_APPLY_NOT_APPROVED';

    /**
     * @param list<string> $argv
     */
    public static function refuseApply(array $argv): ?string
    {
        foreach (['--apply', '--execute', '--write'] as $flag) {
            if (in_array($flag, $argv, true)) {
                return self::APPLY_NOT_APPROVED;
            }
        }

        return null;
    }
}
