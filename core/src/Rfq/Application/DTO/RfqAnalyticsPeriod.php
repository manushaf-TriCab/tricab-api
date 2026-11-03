<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/**
 * The reporting window for `GET /api/v1/rfqs/analytics`: whole Melbourne
 * calendar days, `from` to `to` inclusive.
 *
 * Either `days` (7, 30 or 90, ending today, as tc-web's buttons),
 * `from` + `to` as `YYYY-MM-DD`, or `period=all`. tc-web built custom windows
 * from browser-local midnight but bucketed by UTC date; here the window and
 * the daily buckets are the same Melbourne days.
 *
 * All time has no lower bound: `contains()` takes anything before tomorrow,
 * and `start` only says where the trend begins (`beginningOn()`, from the
 * oldest RFQ). It has no previous window and its trend is monthly.
 */
final class RfqAnalyticsPeriod
{
    public const TIMEZONE = 'Australia/Melbourne';
    public const PRESET_DAYS = [7, 30, 90];
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;

    private function __construct(
        /** Midnight on the first day, Melbourne time. */
        public readonly DateTimeImmutable $start,
        /** Midnight after the last day, Melbourne time (exclusive). */
        public readonly DateTimeImmutable $end,
        public readonly bool $allTime = false,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @throws InvalidRfqValue
     */
    public static function fromQueryParams(array $params, DateTimeImmutable $now): self
    {
        $zone = new DateTimeZone(self::TIMEZONE);
        $from = self::param($params, 'from');
        $to = self::param($params, 'to');
        $days = self::param($params, 'days');
        $all = self::param($params, 'period');

        if ($all !== '') {
            if ($all !== 'all') {
                throw InvalidRfqValue::forOneOf('analytics period', $all, ['all']);
            }
            if ($from !== '' || $to !== '' || $days !== '') {
                throw InvalidRfqValue::forOneOf('analytics period', 'period=all with days or from/to', ['period=all', 'days', 'from and to']);
            }

            return self::allTime($now);
        }

        if ($from !== '' || $to !== '') {
            if ($days !== '') {
                throw InvalidRfqValue::forOneOf('analytics period', 'days with from/to', ['days', 'from and to']);
            }
            $first = self::date($from, 'from', $zone);
            $last = self::date($to, 'to', $zone);
            if ($last < $first) {
                throw InvalidRfqValue::forOneOf('analytics period', "{$from} to {$to}", ['a from date on or before the to date']);
            }
            $period = new self($first, $last->modify('+1 day'));
            if ($period->days() > self::MAX_DAYS) {
                throw InvalidRfqValue::outOfRange('analytics period in days', $period->days(), 1, self::MAX_DAYS);
            }

            return $period;
        }

        $count = $days === '' ? self::DEFAULT_DAYS : (int) $days;
        if ($days !== '' && (preg_match('/^\d{1,3}$/', $days) !== 1 || !in_array($count, self::PRESET_DAYS, true))) {
            throw InvalidRfqValue::forOneOf('analytics days', $days, array_map('strval', self::PRESET_DAYS));
        }

        return self::lastDays($count, $now);
    }

    /** The last `$days` Melbourne days, today included. */
    public static function lastDays(int $days, DateTimeImmutable $now): self
    {
        $today = $now->setTimezone(new DateTimeZone(self::TIMEZONE))->setTime(0, 0);

        return new self($today->modify('-' . ($days - 1) . ' days'), $today->modify('+1 day'));
    }

    /** Everything up to today; the trend starts today until `beginningOn()` moves it back. */
    public static function allTime(DateTimeImmutable $now): self
    {
        $today = self::lastDays(1, $now);

        return new self($today->start, $today->end, true);
    }

    /** All time, its trend starting on the Melbourne day of `$first` (never after today). */
    public function beginningOn(?DateTimeImmutable $first): self
    {
        if (!$this->allTime || $first === null) {
            return $this;
        }
        $day = $first->setTimezone(new DateTimeZone(self::TIMEZONE))->setTime(0, 0);

        return new self(min($day, $this->start), $this->end, true);
    }

    /** The window of the same number of days immediately before this one; all time has none. */
    public function previous(): ?self
    {
        if ($this->allTime) {
            return null;
        }

        return new self($this->start->modify('-' . $this->days() . ' days'), $this->start);
    }

    public function contains(?DateTimeImmutable $at): bool
    {
        return $at !== null && ($this->allTime || $at >= $this->start) && $at < $this->end;
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days;
    }

    /** Melbourne calendar date of an instant, `YYYY-MM-DD`. */
    public static function day(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    /** `month` for all time, which can run for years; `day` otherwise. */
    public function trendInterval(): string
    {
        return $this->allTime ? 'month' : 'day';
    }

    /** The trend bucket of an instant: its Melbourne day, or `YYYY-MM` for all time. */
    public function bucket(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone(self::TIMEZONE))->format($this->allTime ? 'Y-m' : 'Y-m-d');
    }

    /** @return list<string> every trend bucket in the window, in order */
    public function bucketKeys(): array
    {
        if (!$this->allTime) {
            return $this->dayKeys();
        }
        $keys = [];
        for ($month = $this->start->modify('first day of this month'); $month < $this->end; $month = $month->modify('first day of next month')) {
            $keys[] = $month->format('Y-m');
        }

        return $keys;
    }

    /** @return list<string> every day in the window, in order */
    public function dayKeys(): array
    {
        $keys = [];
        for ($day = $this->start; $day < $this->end; $day = $day->modify('+1 day')) {
            $keys[] = $day->format('Y-m-d');
        }

        return $keys;
    }

    /** @return array{from: string, to: string, timezone: string, days: int, allTime: bool} */
    public function toArray(): array
    {
        return [
            'from' => $this->start->format('Y-m-d'),
            'to' => $this->end->modify('-1 day')->format('Y-m-d'),
            'timezone' => self::TIMEZONE,
            'days' => $this->days(),
            'allTime' => $this->allTime,
        ];
    }

    /** @param array<array-key, mixed> $params */
    private static function param(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private static function date(string $value, string $name, DateTimeZone $zone): DateTimeImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone)
            : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw InvalidRfqValue::forOneOf("analytics {$name} date", $value, ['YYYY-MM-DD']);
        }

        return $parsed;
    }
}
