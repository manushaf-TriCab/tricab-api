<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.8 — Single accessory/DV product migration pilot gates.
 *
 * Dry-run CLI refuses any write flags.
 * Apply CLI requires explicit F12-only non-production approval.
 */
final class ProductMigrationPilotCliGuard
{
    public const APPLY_NOT_APPROVED = 'PILOT_APPLY_NOT_APPROVED';
    public const NON_PRODUCTION_CONFIRMATION_REQUIRED = 'PILOT_NON_PRODUCTION_CONFIRMATION_REQUIRED';
    public const SOURCE_DRIFT = 'PILOT_SOURCE_DRIFT';
    public const TARGET_COLLISION = 'PILOT_TARGET_COLLISION';
    public const RECONCILIATION_FAILED = 'PILOT_RECONCILIATION_FAILED';
    public const NOT_IDEMPOTENT = 'PILOT_NOT_IDEMPOTENT';
    public const PUBLICATION_SAFETY_FAILED = 'PILOT_PUBLICATION_SAFETY_FAILED';
    public const APPROVED_STYLE = 'F12';

    /**
     * Dry-run / select CLI: refuse any apply/write flags.
     *
     * @param list<string> $argv
     */
    public static function refuseApply(array $argv): ?string
    {
        foreach (['--apply', '--execute', '--write', '--pilot-apply-approved'] as $flag) {
            if (in_array($flag, $argv, true)) {
                return self::APPLY_NOT_APPROVED;
            }
        }

        return null;
    }

    /**
     * Apply CLI: require explicit F12 pilot approval flags.
     *
     * @param list<string> $argv
     */
    public static function validateApplyArgs(array $argv): ?string
    {
        if (!in_array('--apply', $argv, true)) {
            return self::APPLY_NOT_APPROVED;
        }
        if (!in_array('--pilot', $argv, true)) {
            return self::APPLY_NOT_APPROVED;
        }
        if (!in_array('--confirm-non-production', $argv, true)) {
            return self::NON_PRODUCTION_CONFIRMATION_REQUIRED;
        }
        if (!in_array('--pilot-apply-approved', $argv, true)) {
            return self::APPLY_NOT_APPROVED;
        }

        foreach ($argv as $arg) {
            $lower = strtolower((string) $arg);
            if (
                in_array($lower, [
                    '--all',
                    '--bulk',
                    '--cable',
                    '--cables',
                    '--select-best-accessory',
                    '--select-all',
                ], true)
                || str_starts_with($lower, '--styles=')
                || str_starts_with($lower, '--style-codes=')
                || str_starts_with($lower, '--products=')
            ) {
                return self::APPLY_NOT_APPROVED;
            }
        }

        $styles = self::parseStyles($argv);
        if ($styles === []) {
            return self::APPLY_NOT_APPROVED;
        }
        if (count($styles) !== 1) {
            return self::APPLY_NOT_APPROVED;
        }
        if (strtoupper($styles[0]) !== self::APPROVED_STYLE) {
            return self::APPLY_NOT_APPROVED;
        }

        return null;
    }

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    public static function parseStyles(array $argv): array
    {
        $styles = [];
        foreach ($argv as $arg) {
            $raw = (string) $arg;
            if (str_starts_with($raw, '--style=')) {
                $value = substr($raw, strlen('--style='));
                foreach (preg_split('/[,\s]+/', $value) ?: [] as $part) {
                    $trimmed = strtoupper(trim((string) $part));
                    if ($trimmed !== '') {
                        $styles[] = $trimmed;
                    }
                }
            }
        }

        return array_values(array_unique($styles));
    }
}
