<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/**
 * The daily copy of the Odoo lists: **once a day, always a full sweep** (Phase 1, Q5). The
 * portal's 15-minute timer is not carried: it would keep the serverless database awake all
 * day. 03:00 in Melbourne, an hour when nobody is closing a month.
 *
 * It fails (so the run's audit row says so) when the sync fails, and skips quietly when a
 * run is already going. It returns counts, and the key's days left with a warning flag once
 * the key is within 30 days of expiring, so the audit trail carries the warning.
 *
 * Safe to repeat: the write is idempotent.
 */
final class OdooSyncJob implements FinanceJob
{
    public const NAME = 'odoo.sync';

    public function __construct(private readonly OdooSyncService $sync)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function schedule(): FinanceJobSchedule
    {
        return FinanceJobSchedule::dailyAt('03:00');
    }

    public function run(DateTimeImmutable $now): array
    {
        $daysLeft = $this->sync->config()->keyDaysLeft($now);
        $facts = [
            'keyDaysLeft' => $daysLeft,
            'keyWarning' => $this->sync->config()->keyExpiresSoon($now),
        ];

        try {
            $run = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        } catch (OdooSyncBusy) {
            return ['status' => 'skipped', 'reason' => 'a sync is already running'] + $facts;
        }
        if ($run->status !== OdooSyncRun::STATUS_SUCCEEDED) {
            throw new RuntimeException('The Odoo sync failed: ' . ($run->error ?? 'no reason recorded') . ($facts['keyWarning'] ? sprintf(' (the API key expires in %d days)', (int) $daysLeft) : ''));
        }

        $facts = ['status' => $run->status, 'mode' => $run->mode] + $facts;
        foreach ($run->counts as $list => $counts) {
            foreach ($counts as $what => $number) {
                $facts[str_replace('-', '_', $list) . '_' . $what] = $number;
            }
        }

        return $facts;
    }
}
