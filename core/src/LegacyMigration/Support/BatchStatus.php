<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

final class BatchStatus
{
    public const CREATED = 'created';
    public const EXTRACTED = 'extracted';
    public const NORMALIZED = 'normalized';
    public const VALIDATED = 'validated';
    public const PREVIEW = 'preview';
    public const APPROVED = 'approved';
    public const EXECUTING = 'executing';
    public const COMPLETED = 'completed';
    public const ROLLED_BACK = 'rolled-back';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::CREATED,
            self::EXTRACTED,
            self::NORMALIZED,
            self::VALIDATED,
            self::PREVIEW,
            self::APPROVED,
            self::EXECUTING,
            self::COMPLETED,
            self::ROLLED_BACK,
            self::CANCELLED,
            self::FAILED,
        ];
    }
}
