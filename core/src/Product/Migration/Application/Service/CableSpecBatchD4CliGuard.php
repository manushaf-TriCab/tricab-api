<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.7 Batch D.4 — explicit operator approval gate for cable spec target apply.
 */
final class CableSpecBatchD4CliGuard
{
    public const APPLY_NOT_APPROVED = 'CABLE_SPEC_D4_APPLY_NOT_APPROVED';
    public const NON_PRODUCTION_CONFIRMATION_REQUIRED = 'CABLE_SPEC_D4_NON_PRODUCTION_CONFIRMATION_REQUIRED';
    public const LIVE_PREFLIGHT_DRIFT = 'CABLE_SPEC_D4_LIVE_PREFLIGHT_DRIFT';
    public const MAPPING_CONFLICT = 'CABLE_SPEC_D4_MAPPING_CONFLICT';
    public const DEFINITION_CONFLICT = 'CABLE_SPEC_D4_DEFINITION_CONFLICT';
    public const NOT_IDEMPOTENT = 'D4_NOT_IDEMPOTENT';

    /**
     * @param list<string> $argv
     */
    public static function validateApplyArgs(array $argv): ?string
    {
        if (!in_array('--apply', $argv, true)) {
            return self::APPLY_NOT_APPROVED;
        }
        if (!in_array('--batch-d4', $argv, true)) {
            return self::APPLY_NOT_APPROVED;
        }
        if (!in_array('--confirm-non-production', $argv, true)) {
            return self::NON_PRODUCTION_CONFIRMATION_REQUIRED;
        }
        if (!in_array('--cable-spec-d4-apply-approved', $argv, true)) {
            return self::APPLY_NOT_APPROVED;
        }

        return null;
    }
}
