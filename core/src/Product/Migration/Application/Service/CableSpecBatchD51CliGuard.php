<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.7 Batch D.5.1 — read-only investigation guard.
 */
final class CableSpecBatchD51CliGuard
{
    public const APPLY_NOT_AVAILABLE = 'CABLE_SPEC_D5_1_APPLY_NOT_AVAILABLE';

    /**
     * @param list<string> $argv
     */
    public static function refuseApply(array $argv): ?string
    {
        foreach (['--apply', '--execute', '--write'] as $flag) {
            if (in_array($flag, $argv, true)) {
                return self::APPLY_NOT_AVAILABLE;
            }
        }

        return null;
    }
}
