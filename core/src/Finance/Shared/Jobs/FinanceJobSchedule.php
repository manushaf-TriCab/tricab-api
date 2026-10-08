<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * When a finance job is due, as a "slot": a string that names one occurrence and is the
 * same for everyone who asks within it. The runner claims `(job, slot)`, so a slot runs
 * once however many runners ask.
 *
 *  - {@see dailyAt}: once a day from a wall-clock time **in a stated time zone**. The apps
 *    disagree on time zone today (Tri-AP scans on Sydney time and marks overdue on Melbourne
 *    time), so a job states its own; {@see DEFAULT_TIME_ZONE} is only the default. Before
 *    the time there is no slot, so a runner that was down all of yesterday does not run
 *    yesterday's job at 3 am; it runs today's at the time. After it, the slot is today's,
 *    so a runner that comes back at 2 pm still runs this morning's job once.
 *  - {@see everyMinutes}: on a fixed grid of N minutes from the Unix epoch, so every runner
 *    agrees on the boundaries.
 */
final class FinanceJobSchedule
{
    public const DEFAULT_TIME_ZONE = 'Australia/Melbourne';

    private function __construct(
        private readonly string $kind,
        private readonly string $time,
        private readonly DateTimeZone $zone,
        private readonly int $minutes,
    ) {
    }

    public static function dailyAt(string $time, string $timeZone = self::DEFAULT_TIME_ZONE): self
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
            throw new InvalidArgumentException('A daily time is HH:MM, 24-hour.');
        }

        return new self('daily', $time, new DateTimeZone($timeZone), 0);
    }

    public static function everyMinutes(int $minutes): self
    {
        if ($minutes < 1 || $minutes > 1440) {
            throw new InvalidArgumentException('An interval is 1 to 1440 minutes.');
        }

        return new self('interval', '', new DateTimeZone('UTC'), $minutes);
    }

    /** The slot that is due at `$now`, or null when the job is not due yet. */
    public function dueSlot(DateTimeImmutable $now): ?string
    {
        if ($this->kind === 'interval') {
            return sprintf('I%d:%d', $this->minutes, intdiv($now->getTimestamp(), $this->minutes * 60));
        }

        $local = $now->setTimezone($this->zone);
        $today = new DateTimeImmutable($local->format('Y-m-d') . ' ' . $this->time . ':00', $this->zone);
        if ($now < $today) {
            return null;
        }

        return sprintf('D%s@%s@%s', $local->format('Y-m-d'), $this->time, $this->zone->getName());
    }
}
