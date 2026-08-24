<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Batch D.1 CLI safety — refuse any apply/execute/write flags.
 */
final class CableSpecBatchD1CliGuard
{
    public const APPLY_NOT_AVAILABLE = 'CABLE_SPEC_FIELD_MAPPING_APPLY_NOT_AVAILABLE';

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
