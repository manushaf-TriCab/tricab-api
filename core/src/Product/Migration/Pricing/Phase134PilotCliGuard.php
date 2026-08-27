<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

final class Phase134PilotCliGuard
{
    public const PILOT_STYLE_REQUIRED = 'PILOT_STYLE_REQUIRED';
    public const APPLY_CONFIRMATIONS_REQUIRED = 'APPLY_CONFIRMATIONS_REQUIRED';
    public const ROLLBACK_CONFIRMATIONS_REQUIRED = 'ROLLBACK_CONFIRMATIONS_REQUIRED';
    public const MAX_VARIANTS_EXCEEDED = 'MAX_VARIANTS_EXCEEDED_NEEDS_APPROVAL';
    public const HARD_MAX = 25;

    /**
     * @param list<string> $argv
     */
    public static function parseStyle(array $argv): ?string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--style=')) {
                $style = strtoupper(trim(substr($arg, 8)));

                return $style !== '' ? $style : null;
            }
        }

        return null;
    }

    /**
     * @param list<string> $argv
     */
    public static function parseMaxVariants(array $argv, int $default = 20): int
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--max-variants=')) {
                $n = (int) substr($arg, 15);

                return max(1, $n);
            }
        }

        return $default;
    }

    /**
     * @param list<string> $argv
     */
    public static function wantsApply(array $argv): bool
    {
        return in_array('--apply', $argv, true);
    }

    /**
     * @param list<string> $argv
     */
    public static function applyConfirmationsOk(array $argv): bool
    {
        return in_array('--apply', $argv, true)
            && in_array('--confirm-non-production', $argv, true)
            && in_array('--confirm-pilot', $argv, true);
    }

    /**
     * @param list<string> $argv
     */
    public static function parseBatchId(array $argv): ?string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--batch=')) {
                $id = trim(substr($arg, 8));

                return $id !== '' ? $id : null;
            }
        }

        return null;
    }

    /**
     * @param list<string> $argv
     */
    public static function rollbackConfirmationsOk(array $argv): bool
    {
        return in_array('--confirm-non-production', $argv, true)
            && in_array('--confirm-rollback', $argv, true)
            && self::parseBatchId($argv) !== null;
    }
}
