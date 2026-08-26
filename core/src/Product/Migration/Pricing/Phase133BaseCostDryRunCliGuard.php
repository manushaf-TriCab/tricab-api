<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * CLI safety for Phase 13.3 zero-write dry run.
 */
final class Phase133BaseCostDryRunCliGuard
{
    public const APPLY_NOT_APPROVED = 'PHASE_13_3_APPLY_NOT_APPROVED';
    public const NON_PRODUCTION_CONFIRMATION_REQUIRED = 'NON_PRODUCTION_CONFIRMATION_REQUIRED';
    public const FORBIDDEN_WRITE_FLAG = 'PHASE_13_3_FORBIDDEN_WRITE_FLAG';

    /**
     * @param list<string> $argv
     */
    public static function refuseApply(array $argv): ?string
    {
        foreach ($argv as $arg) {
            $a = strtolower($arg);
            if (in_array($a, ['--apply', '--execute', '--write', '--seed', '--migrate'], true)) {
                return self::APPLY_NOT_APPROVED . ': ' . $arg . ' is refused in Phase 13.3 dry-run';
            }
        }

        return null;
    }

    /**
     * @param list<string> $argv
     */
    public static function requiresNonProductionConfirm(array $argv): bool
    {
        return !in_array('--confirm-non-production', $argv, true)
            && !in_array('--offline', $argv, true);
    }
}
