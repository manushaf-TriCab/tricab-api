<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Readiness;

/**
 * Explicit migration readiness statuses for catalogue mapping (Phase 12.6).
 * Do not collapse these into a generic ERROR.
 */
final class MigrationReadinessStatus
{
    public const READY = 'READY';
    public const REVIEW = 'REVIEW';
    public const BLOCKED = 'BLOCKED';
    public const SOURCE_ONLY = 'SOURCE_ONLY';
    public const INVALID = 'INVALID';
    public const EXCLUDED = 'EXCLUDED';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::READY,
            self::REVIEW,
            self::BLOCKED,
            self::SOURCE_ONLY,
            self::INVALID,
            self::EXCLUDED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
