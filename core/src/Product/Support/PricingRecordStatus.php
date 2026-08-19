<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Support;

/**
 * Temporal/lifecycle status for admin base-cost records.
 */
final class PricingRecordStatus
{
    public const CURRENT = 'current';
    public const SCHEDULED = 'scheduled';
    public const EXPIRED = 'expired';
    public const INACTIVE = 'inactive';
    public const MISSING = 'missing';

    public static function resolve(
        bool $isActive,
        ?string $effectiveFromUtc,
        ?string $effectiveToUtc,
        ?\DateTimeImmutable $now = null,
    ): string {
        if (!$isActive) {
            return self::INACTIVE;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = self::parseUtc($effectiveFromUtc);
        $to = self::parseUtc($effectiveToUtc);

        if ($from !== null && $from > $now) {
            return self::SCHEDULED;
        }

        if ($to !== null && $to < $now) {
            return self::EXPIRED;
        }

        return self::CURRENT;
    }

    private static function parseUtc(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
