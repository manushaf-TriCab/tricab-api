<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Batch D.3 final dry-run CLI safety — refuse any apply/execute/write flags.
 */
final class CableSpecBatchD3CliGuard
{
    public const APPLY_NOT_APPROVED = 'CABLE_SPEC_D3_APPLY_NOT_APPROVED';

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
