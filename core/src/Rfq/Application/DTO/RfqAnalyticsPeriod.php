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
 * Either `days` (7, 30 or 90, ending today, as tc-web's buttons) or
 * `from` + `to` as `YYYY-MM-DD`. tc-web built custom windows from
 * browser-local midnight but bucketed by UTC date; here the window and the
 * daily buckets are the same Melbourne days.
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

    /** The window of the same number of days immediately before this one. */
    public function previous(): self
    {
        return new self($this->start->modify('-' . $this->days() . ' days'), $this->start);
    }

    public function contains(?DateTimeImmutable $at): bool
    {
        return $at !== null && $at >= $this->start && $at < $this->end;
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

    /** @return list<string> every day in the window, in order */
    public function dayKeys(): array
    {
        $keys = [];
        for ($day = $this->start; $day < $this->end; $day = $day->modify('+1 day')) {
            $keys[] = $day->format('Y-m-d');
        }

        return $keys;
    }

    /** @return array{from: string, to: string, timezone: string, days: int} */
    public function toArray(): array
    {
        return [
            'from' => $this->start->format('Y-m-d'),
            'to' => $this->end->modify('-1 day')->format('Y-m-d'),
            'timezone' => self::TIMEZONE,
            'days' => $this->days(),
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
