<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What the sync needs to know about the Odoo connection that is not a secret: which
 * companies to read, and when the API key expires (an Odoo.sh key is issued for a limited
 * time and, once expired, every sync fails until a new one is issued).
 */
final readonly class OdooSyncConfig
{
    public const KEY_EXPIRY_WARNING_DAYS = 30;

    /**
     * @param list<int> $companyIds
     * @param string $keyExpires `yyyy-mm-dd`, or empty when not known
     */
    public function __construct(
        public array $companyIds,
        public string $keyExpires = '',
    ) {
    }

    /** Whole days until the key expires (negative once it has), or null when the date is not set or not a date. */
    public function keyDaysLeft(DateTimeImmutable $today): ?int
    {
        $expires = DateTimeImmutable::createFromFormat('!Y-m-d', $this->keyExpires, new DateTimeZone('UTC'));
        if ($expires === false || $expires->format('Y-m-d') !== $this->keyExpires) {
            return null;
        }
        $from = new DateTimeImmutable($today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), new DateTimeZone('UTC'));

        return (int) $from->diff($expires)->format('%r%a');
    }

    public function keyExpiresSoon(DateTimeImmutable $today): bool
    {
        $left = $this->keyDaysLeft($today);

        return $left !== null && $left <= self::KEY_EXPIRY_WARNING_DAYS;
    }
}
