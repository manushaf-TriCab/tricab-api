<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Batch D.2 operator-decision CLI safety — refuse any apply/execute/write flags.
 */
final class CableSpecBatchD2DecisionCliGuard
{
    public const APPLY_NOT_AVAILABLE = 'CABLE_SPEC_D2_DECISION_APPLY_NOT_AVAILABLE';

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
