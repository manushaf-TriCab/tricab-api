<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TriCab\Core\Analytics\Application\Exception\AnalyticsUnavailable;
use TriCab\Core\Analytics\Application\Exception\InvalidActivityRequest;
use TriCab\Core\Analytics\Contract\LastLoginAnalyticsRepository;
use TriCab\Core\Analytics\Contract\LivePresenceStore;
use TriCab\Core\Analytics\Contract\UserActivityRepository;
use TriCab\Core\Analytics\Domain\ActivityModuleCatalog;
use TriCab\Core\Analytics\Domain\ReportingCalendar;
use TriCab\Core\Analytics\Infrastructure\InMemoryLastLoginAnalyticsRepository;
use TriCab\Core\Analytics\Infrastructure\InMemoryLivePresenceStore;
use TriCab\Core\Shared\Time\Clock;

/**
 * Aggregate analytics for the admin dashboard.
 *
 * Never reports zero for something that was not measured: buckets before collection began are null, and
 * while collection is switched off, "live" and "today" are null rather than an apparent quiet day.
 *
 * When activity collection has never run, last successful sign-in is used instead. That is one timestamp
 * per user (overwritten on each login), with no platform or module.
 *
 * Status, shared by all three responses:
 *   ok              collecting, with data
 *   no_data         collecting, nothing recorded yet
 *   paused          not collecting; history is shown, current figures are null
 *   last_login      collection off; figures are last successful sign-in
 *   not_collecting  not collecting and nothing was ever recorded
 */
final class ActivityAnalyticsService
{
    /** A user is live while an active session shows qualifying activity within this window. */
    public const LIVE_WINDOW_SECONDS = 120;

    private const DAY_RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];
    private const MODULE_RANGES = ['today' => 1, '7d' => 7, '30d' => 30];

    public function __construct(
        private readonly UserActivityRepository $activity,
        private readonly Clock $clock,
        private readonly bool $collectionEnabled = false,
        private readonly LastLoginAnalyticsRepository $lastLogin = new InMemoryLastLoginAnalyticsRepository(),
        private readonly LivePresenceStore $presence = new InMemoryLivePresenceStore(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function activeUsers(string $range): array
    {
        if ($range !== 'today' && !array_key_exists($range, self::DAY_RANGES)) {
            throw new InvalidActivityRequest('range must be today, 7d, 30d or 90d.');
        }

        return $this->guard(function () use ($range): array {
            $now = $this->now();
            $source = $this->source();
            $today = ReportingCalendar::localDate($now);

            $points = $range === 'today'
                ? $this->hourlyPoints($now, $source)
                : $this->dailyPoints($now, self::DAY_RANGES[$range], $source, $today);

            $todayRow = null;
            if ($source['current'] && $source['since'] !== null) {
                $todayRow = $this->dailyRows($source, $today, $today)[$today] ?? self::zeroRow();
            }

            $live = $this->liveTotals($source, $now);

            return [
                'range' => $range,
                'granularity' => $range === 'today' ? 'hour' : 'day',
                'timezone' => ReportingCalendar::TIMEZONE,
                'status' => $source['status'],
                'collectingSince' => $source['since'],
                'points' => $points,
                'summary' => [
                    'today' => $todayRow === null ? null : [
                        'total' => $todayRow['total'],
                        'web' => $todayRow['web'],
                        'mobile' => $todayRow['mobile'],
                        'staff' => $todayRow['staff'],
                        'customer' => $todayRow['customer'],
                    ],
                    'liveNow' => $live === null ? null : $live['total'],
                ],
                'generatedAt' => $now->format(DATE_ATOM),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function liveUsers(): array
    {
        return $this->guard(function (): array {
            $now = $this->now();
            $source = $this->source();

            return [
                'status' => $source['status'],
                'windowSeconds' => self::LIVE_WINDOW_SECONDS,
                'live' => $this->liveTotals($source, $now),
                'generatedAt' => $now->format(DATE_ATOM),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function moduleActivity(string $range, string $sort): array
    {
        if (!array_key_exists($range, self::MODULE_RANGES)) {
            throw new InvalidActivityRequest('range must be today, 7d or 30d.');
        }
        if ($sort !== 'live' && $sort !== 'users') {
            throw new InvalidActivityRequest('sort must be live or users.');
        }

        return $this->guard(function () use ($range, $sort): array {
            $now = $this->now();
            $source = $this->source();
            $since = $source['since'];
            $dates = ReportingCalendar::lastDates($now, self::MODULE_RANGES[$range]);
            $live = $this->collectionEnabled
                ? $this->activity->liveByModule($this->liveSince($now))
                : $this->presence->liveByModule($this->liveSince($now));
            if ($live === []) {
                $live = null;
            }

            $rows = [];
            if ($since !== null && $source['kind'] === 'activity') {
                foreach ($this->activity->moduleActivity($dates[0], $dates[count($dates) - 1]) as $row) {
                    $rows[$row['module']] = $row;
                }
            }

            $totalEvents = 0;
            foreach ($rows as $row) {
                $totalEvents += $row['events'];
            }

            $modules = [];
            foreach (ActivityModuleCatalog::codes() as $code) {
                $row = $rows[$code] ?? null;
                $liveUsers = $live === null ? null : ($live[$code] ?? 0);
                if ($row === null && !$liveUsers) {
                    continue;
                }
                $events = $row['events'] ?? 0;
                $modules[] = [
                    'code' => $code,
                    'label' => ActivityModuleCatalog::label($code),
                    'users' => $row['users'] ?? 0,
                    'webUsers' => $row['webUsers'] ?? 0,
                    'mobileUsers' => $row['mobileUsers'] ?? 0,
                    'liveUsers' => $liveUsers,
                    'events' => $events,
                    'eventShare' => $totalEvents > 0 ? round($events * 100 / $totalEvents, 1) : null,
                ];
            }

            usort($modules, static function (array $a, array $b) use ($sort): int {
                $primary = $sort === 'live'
                    ? [($b['liveUsers'] ?? 0) <=> ($a['liveUsers'] ?? 0), $b['users'] <=> $a['users']]
                    : [$b['users'] <=> $a['users'], ($b['liveUsers'] ?? 0) <=> ($a['liveUsers'] ?? 0)];

                return $primary[0] ?: ($primary[1] ?: strcmp($a['label'], $b['label']));
            });

            return [
                'range' => $range,
                'sort' => $sort,
                'timezone' => ReportingCalendar::TIMEZONE,
                'status' => $source['status'],
                'collectingSince' => $since,
                'windowSeconds' => self::LIVE_WINDOW_SECONDS,
                'modules' => $modules,
                'totals' => [
                    'events' => $totalEvents,
                    'liveUsers' => $live === null
                        ? ($source['current'] ? ($this->liveTotals($source, $now)['total'] ?? null) : null)
                        : array_sum($live),
                ],
                'generatedAt' => $now->format(DATE_ATOM),
            ];
        });
    }

    /**
     * @param array{kind: 'activity'|'last_login'|'none', status: string, since: ?string, current: bool} $source
     * @return list<array{bucket: string, label: string, web: int|null, mobile: int|null, total: int|null}>
     */
    private function dailyPoints(DateTimeImmutable $now, int $days, array $source, string $today): array
    {
        $dates = ReportingCalendar::lastDates($now, $days);
        $since = $source['since'];
        $rows = [];
        if ($since !== null) {
            $yesterday = $dates[count($dates) - 2] ?? null;
            if ($yesterday !== null) {
                $rows = $this->dailyRows($source, $dates[0], $yesterday);
            }
            if ($source['current']) {
                $rows += $this->dailyRows($source, $today, $today);
            }
        }

        $points = [];
        foreach ($dates as $date) {
            $measured = $since !== null && $date >= $since && ($date !== $today || $source['current']);
            $row = $rows[$date] ?? self::zeroRow();
            $points[] = [
                'bucket' => $date,
                'label' => $date,
                'web' => $measured ? $row['web'] : null,
                'mobile' => $measured ? $row['mobile'] : null,
                'total' => $measured ? $row['total'] : null,
            ];
        }

        return $points;
    }

    /**
     * @param array{kind: 'activity'|'last_login'|'none', status: string, since: ?string, current: bool} $source
     * @return list<array{bucket: string, label: string, web: int|null, mobile: int|null, total: int|null}>
     */
    private function hourlyPoints(DateTimeImmutable $now, array $source): array
    {
        $hours = ReportingCalendar::todayHoursUtc($now);
        $measured = $source['since'] !== null && $source['current'];
        $rows = [];
        if ($measured) {
            $hourly = $source['kind'] === 'last_login'
                ? $this->lastLogin->hourlyLastLogins($hours[0], $hours[count($hours) - 1])
                : $this->activity->hourlyActiveUsers($hours[0], $hours[count($hours) - 1]);
            foreach ($hourly as $row) {
                $rows[$row['bucket']] = $row;
            }
        }

        $zone = ReportingCalendar::zone();
        $points = [];
        foreach ($hours as $hour) {
            $row = $rows[$hour->format('Y-m-d\TH:i:s')] ?? self::zeroRow();
            $local = $hour->setTimezone($zone);
            $points[] = [
                'bucket' => $local->format(DATE_ATOM),
                'label' => $local->format('H:i'),
                'web' => $measured ? $row['web'] : null,
                'mobile' => $measured ? $row['mobile'] : null,
                'total' => $measured ? $row['total'] : null,
            ];
        }

        return $points;
    }

    /**
     * @param array{kind: 'activity'|'last_login'|'none', status: string, since: ?string, current: bool} $source
     * @return array<string, array{bucket: string, web: int|null, mobile: int|null, total: int, staff: int, customer: int}>
     */
    private function dailyRows(array $source, string $from, string $to): array
    {
        $rows = [];
        $list = $source['kind'] === 'last_login'
            ? $this->lastLogin->dailyLastLogins($from, $to)
            : $this->activity->dailyActiveUsers($from, $to);
        foreach ($list as $row) {
            $rows[$row['bucket']] = $row;
        }

        return $rows;
    }

    /**
     * @return array{bucket: string, web: int|null, mobile: int|null, total: int, staff: int, customer: int}
     */
    private static function zeroRow(): array
    {
        return ['bucket' => '', 'web' => 0, 'mobile' => 0, 'total' => 0, 'staff' => 0, 'customer' => 0];
    }

    /**
     * @return array{kind: 'activity'|'last_login'|'none', status: string, since: ?string, current: bool}
     */
    private function source(): array
    {
        $activitySince = $this->activity->firstActivityDate();
        if ($this->collectionEnabled) {
            return [
                'kind' => 'activity',
                'status' => $activitySince === null ? 'no_data' : 'ok',
                'since' => $activitySince,
                'current' => true,
            ];
        }
        if ($activitySince !== null) {
            return [
                'kind' => 'activity',
                'status' => 'paused',
                'since' => $activitySince,
                'current' => false,
            ];
        }
        $loginSince = $this->lastLogin->firstLoginDate();
        if ($loginSince !== null) {
            return [
                'kind' => 'last_login',
                'status' => 'last_login',
                'since' => $loginSince,
                'current' => true,
            ];
        }

        return [
            'kind' => 'none',
            'status' => 'not_collecting',
            'since' => null,
            'current' => false,
        ];
    }

    /**
     * @param array{kind: 'activity'|'last_login'|'none', status: string, since: ?string, current: bool} $source
     * @return array{total: int, web: int|null, mobile: int|null, staff: int, customer: int}|null
     */
    private function liveTotals(array $source, DateTimeImmutable $now): ?array
    {
        if ($this->collectionEnabled) {
            return $this->activity->liveTotals($this->liveSince($now));
        }

        $presence = $this->presence->liveTotals($this->liveSince($now));
        if ($presence['total'] > 0) {
            return $presence;
        }

        if ($source['kind'] === 'last_login') {
            return $this->lastLogin->liveTotals($this->liveSince($now));
        }

        return null;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function liveSince(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d seconds', self::LIVE_WINDOW_SECONDS));
    }

    /**
     * @param callable(): array<string, mixed> $read
     * @return array<string, mixed>
     */
    private function guard(callable $read): array
    {
        try {
            return $read();
        } catch (Throwable $exception) {
            throw new AnalyticsUnavailable('Activity analytics could not be read: ' . $exception::class, 0, $exception);
        }
    }
}
