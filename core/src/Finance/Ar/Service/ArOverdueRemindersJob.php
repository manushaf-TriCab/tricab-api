<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/**
 * Tri-AR's overdue reminders (decision D44) as a registered job: 08:00 in the Tri-AR zone, so the registry (which runs
 * jobs in name order) runs it after `ar.mailbox-scan`. Safe to repeat: a reminder already sent is not picked again.
 */
final class ArOverdueRemindersJob implements FinanceJob
{
    public const NAME = 'ar.overdue-reminders';

    public function __construct(
        private readonly ArReminderService $reminders,
        private readonly string $timeZone = FinanceJobSchedule::DEFAULT_TIME_ZONE,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function schedule(): FinanceJobSchedule
    {
        return FinanceJobSchedule::dailyAt('08:00', $this->timeZone);
    }

    public function run(DateTimeImmutable $now): array
    {
        $result = $this->reminders->dispatch();

        return [
            'due' => $result['due'],
            'sent' => $result['sent'],
            'suppressed' => $result['suppressed'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
            'unassigned' => $result['unassigned'],
            'capped' => $result['capped'],
        ];
    }
}
