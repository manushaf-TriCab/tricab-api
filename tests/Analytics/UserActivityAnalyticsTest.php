<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Doubles\ClockedActivityThrottle;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Analytics\Application\Exception\ActivityRateLimited;
use TriCab\Core\Analytics\Application\Exception\InvalidActivityRequest;
use TriCab\Core\Analytics\Application\Service\ActivityAnalyticsService;
use TriCab\Core\Analytics\Application\Service\PurgeUserActivityService;
use TriCab\Core\Analytics\Application\Service\RecordActivityService;
use TriCab\Core\Analytics\Domain\ActivityModuleCatalog;
use TriCab\Core\Analytics\Domain\ReportingCalendar;
use TriCab\Core\Analytics\Infrastructure\InMemoryLastLoginAnalyticsRepository;
use TriCab\Core\Analytics\Infrastructure\InMemoryLivePresenceStore;
use TriCab\Core\Analytics\Infrastructure\InMemoryUserActivityRepository;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

final class UserActivityAnalyticsTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryUserActivityRepository $store;
    private RecordActivityService $recorder;
    private ActivityAnalyticsService $analytics;

    protected function setUp(): void
    {
        // 13:00 AEDT, Wednesday 28 October 2026.
        $this->clock = FixedClock::utc('2026-10-28T02:00:00Z');
        $this->store = new InMemoryUserActivityRepository();
        $presence = new InMemoryLivePresenceStore();
        $this->recorder = new RecordActivityService($this->store, new ClockedActivityThrottle($this->clock), $this->clock, true, $presence);
        $this->analytics = new ActivityAnalyticsService($this->store, $this->clock, true, new InMemoryLastLoginAnalyticsRepository(), $presence);
    }

    public function test_web_and_mobile_are_counted_separately_and_once_in_the_combined_total(): void
    {
        $staff = $this->user(1, ['EMPLOYEE']);
        $this->signal($staff, 1, 'web', 'products', 'view');
        $this->signal($staff, 2, 'mobile', 'technical-tools', 'view');
        $this->signal($this->user(2, ['CUSTOMER_USER']), 3, 'mobile', 'products', 'view');

        $today = $this->todaySummary();
        self::assertSame(['total' => 2, 'web' => 1, 'mobile' => 2, 'staff' => 1, 'customer' => 1], $today);

        $live = $this->liveTotals();
        self::assertSame(['total' => 2, 'web' => 1, 'mobile' => 2, 'staff' => 1, 'customer' => 1], $live);
    }

    public function test_several_tabs_count_one_user_and_one_live_module(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        $this->signal($user, 1, 'web', 'products', 'view');
        $this->clock->advance('+10 seconds');
        $this->signal($user, 2, 'web', 'finance', 'view');

        self::assertSame(1, $this->todaySummary()['web']);
        self::assertSame(1, $this->liveTotals()['total']);
        self::assertSame(['finance' => 1], $this->liveModules());
    }

    public function test_background_and_end_stop_live_immediately_and_a_heartbeat_resumes(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        $this->signal($user, 1, 'mobile', 'products', 'view');
        $this->signal($user, 1, 'mobile', 'products', 'background');
        self::assertSame(0, $this->liveTotals()['total']);

        $this->clock->advance('+5 seconds');
        $this->signal($user, 1, 'mobile', 'products', 'heartbeat');
        self::assertSame(1, $this->liveTotals()['total']);

        $this->signal($user, 1, 'mobile', 'products', 'end');
        self::assertSame(0, $this->liveTotals()['total']);
        self::assertSame(1, $this->todaySummary()['mobile'], 'Ending a session does not remove the day it was active.');
    }

    public function test_inactive_sessions_expire_after_the_live_window(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        $this->signal($user, 1, 'web', 'products', 'view');

        $this->clock->advance('+120 seconds');
        self::assertSame(1, $this->liveTotals()['total']);

        $this->clock->advance('+1 second');
        self::assertSame(0, $this->liveTotals()['total']);
        self::assertSame(1, $this->todaySummary()['total']);
    }

    public function test_heartbeats_keep_an_interacting_user_live(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        $this->signal($user, 1, 'web', 'products', 'view');
        for ($i = 0; $i < 5; $i++) {
            $this->clock->advance('+60 seconds');
            $this->signal($user, 1, 'web', 'products', 'heartbeat');
        }

        self::assertSame(1, $this->liveTotals()['total']);
    }

    public function test_module_changes_move_the_live_user_and_count_each_module_used_today(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        $this->signal($user, 1, 'web', 'products', 'view');
        $this->signal($user, 1, 'web', 'products', 'view');
        $this->clock->advance('+30 seconds');
        $this->signal($user, 1, 'web', 'finance', 'view');

        self::assertSame(['finance' => 1], $this->liveModules());

        $modules = $this->modules('today', 'users');
        self::assertSame(['finance', 'products'], array_column($modules, 'code'));
        self::assertSame([1, 1], array_column($modules, 'users'));
        self::assertSame([1, 2], array_column($modules, 'events'));
        self::assertSame([33.3, 66.7], array_column($modules, 'eventShare'));
        self::assertSame(1, $this->todaySummary()['total'], 'Module totals never inflate unique users.');
    }

    public function test_sorts_by_live_users_by_default_and_by_daily_users_on_request(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->signal($this->user($id, ['EMPLOYEE']), $id, 'web', 'products', 'view');
        }
        $this->clock->advance('+200 seconds');
        $live = $this->user(4, ['EMPLOYEE']);
        $this->signal($live, 4, 'web', 'finance', 'view');

        self::assertSame(['finance', 'products'], array_column($this->modules('today', 'live'), 'code'));
        self::assertSame(['products', 'finance'], array_column($this->modules('today', 'users'), 'code'));
        self::assertSame([1, 0], array_column($this->modules('today', 'live'), 'liveUsers'));
    }

    public function test_unknown_modules_are_reported_as_other_and_malformed_ones_are_rejected(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        $this->signal($user, 1, 'web', 'marketing', 'view');
        self::assertSame([ActivityModuleCatalog::OTHER], array_column($this->modules('today', 'live'), 'code'));

        foreach ([
            ['sessionId' => 'not-a-uuid'],
            ['platform' => 'desktop'],
            ['event' => 'click'],
            ['module' => '../admin'],
            ['module' => ''],
        ] as $override) {
            try {
                $this->recorder->record($user, array_merge($this->payload(2, 'web', 'products', 'view'), $override), false);
                self::fail('Expected rejection for ' . json_encode($override, JSON_THROW_ON_ERROR));
            } catch (InvalidActivityRequest) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_session_owned_by_another_user_is_left_untouched(): void
    {
        $owner = $this->user(1, ['EMPLOYEE']);
        $this->signal($owner, 1, 'web', 'products', 'view');
        $this->signal($this->user(2, ['EMPLOYEE']), 1, 'web', 'finance', 'end');

        self::assertSame(['products' => 1], $this->liveModules());
    }

    public function test_act_as_and_disabled_collection_record_nothing(): void
    {
        $user = $this->user(1, ['ADMINISTRATOR']);
        $result = $this->recorder->record($user, $this->payload(1, 'web', 'products', 'view'), true);
        self::assertFalse($result['accepted']);

        $disabled = new RecordActivityService($this->store, new ClockedActivityThrottle($this->clock), $this->clock, false);
        $result = $disabled->record($user, $this->payload(2, 'web', 'products', 'view'), false);
        self::assertSame(['accepted' => false, 'collectionEnabled' => false, 'heartbeatSeconds' => 60], $result);

        self::assertNull($this->store->firstActivityDate());
    }

    public function test_background_or_end_alone_is_not_qualifying_activity(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        $this->signal($user, 1, 'web', 'products', 'background');
        $this->signal($user, 2, 'web', 'products', 'end');

        self::assertNull($this->store->firstActivityDate());
        self::assertSame(0, $this->liveTotals()['total']);
    }

    public function test_signals_are_rate_limited_per_user(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        for ($i = 0; $i < 120; $i++) {
            $this->signal($user, 1, 'web', 'products', 'heartbeat');
        }

        $this->expectException(ActivityRateLimited::class);
        $this->signal($user, 1, 'web', 'products', 'heartbeat');
    }

    public function test_reporting_days_follow_melbourne_midnight(): void
    {
        $user = $this->user(1, ['EMPLOYEE']);
        // 23:59 AEDT on the 27th, then 00:30 AEDT on the 28th.
        $this->clock = FixedClock::utc('2026-10-27T12:59:00Z');
        $this->rebuild();
        $this->signal($user, 1, 'web', 'products', 'view');
        $this->clock->advance('+31 minutes');
        $this->signal($this->user(2, ['EMPLOYEE']), 2, 'web', 'products', 'view');

        $points = $this->points('7d');
        self::assertSame('2026-10-27', $points[5]['bucket']);
        self::assertSame(1, $points[5]['total']);
        self::assertSame('2026-10-28', $points[6]['bucket']);
        self::assertSame(1, $points[6]['total']);
    }

    public function test_daylight_saving_days_have_23_and_25_hourly_buckets(): void
    {
        // Clocks go forward at 02:00 on Sunday 4 October 2026 and back at 03:00 on Sunday 5 April 2026.
        $forward = ReportingCalendar::todayHoursUtc(new \DateTimeImmutable('2026-10-04T23:30:00+11:00'));
        $back = ReportingCalendar::todayHoursUtc(new \DateTimeImmutable('2026-04-05T23:30:00+10:00'));

        self::assertCount(23, $forward);
        self::assertCount(25, $back);

        self::assertSame(
            ['2026-10-03', '2026-10-04', '2026-10-05'],
            ReportingCalendar::lastDates(new \DateTimeImmutable('2026-10-05T00:30:00+11:00'), 3),
        );
        self::assertSame(
            ['2026-04-04', '2026-04-05', '2026-04-06'],
            ReportingCalendar::lastDates(new \DateTimeImmutable('2026-04-06T00:30:00+10:00'), 3),
        );
    }

    public function test_today_hourly_chart_uses_melbourne_hours(): void
    {
        $this->signal($this->user(1, ['EMPLOYEE']), 1, 'web', 'products', 'view');

        $points = $this->points('today');
        self::assertCount(14, $points, 'Midnight to 13:00 AEDT inclusive.');
        self::assertSame('00:00', $points[0]['label']);
        self::assertSame('2026-10-28T13:00:00+11:00', $points[13]['bucket']);
        self::assertSame(1, $points[13]['web']);
        self::assertSame(0, $points[12]['web']);
    }

    public function test_days_before_collection_began_are_null_not_zero(): void
    {
        $this->signal($this->user(1, ['EMPLOYEE']), 1, 'web', 'products', 'view');

        $response = $this->analytics->activeUsers('7d');
        self::assertSame('ok', $response['status']);
        self::assertSame('2026-10-28', $response['collectingSince']);
        $points = $this->points('7d');
        self::assertNull($points[0]['total']);
        self::assertNull($points[5]['web']);
        self::assertSame(1, $points[6]['total']);
    }

    public function test_status_never_reports_unmeasured_figures_as_zero(): void
    {
        $empty = new ActivityAnalyticsService($this->store, $this->clock, false)->activeUsers('30d');
        self::assertSame('not_collecting', $empty['status']);
        self::assertSame(['today' => null, 'liveNow' => null], $empty['summary']);

        $noData = $this->analytics->activeUsers('30d');
        self::assertSame('no_data', $noData['status']);
        self::assertSame(['today' => null, 'liveNow' => 0], $noData['summary'], 'Collecting: nobody live is a measured zero.');

        $this->signal($this->user(1, ['EMPLOYEE']), 1, 'web', 'products', 'view');
        $this->clock->advance('+1 day');
        $paused = new ActivityAnalyticsService($this->store, $this->clock, false);
        $response = $paused->activeUsers('7d');
        self::assertSame('paused', $response['status']);
        self::assertSame(['today' => null, 'liveNow' => null], $response['summary']);
        $points = self::listOf($response['points']);
        self::assertSame(1, $points[5]['total'], 'Collected history stays visible.');
        self::assertNull($points[6]['total'], 'Today is not measured while paused.');
        self::assertNull($paused->liveUsers()['live']);
        $totals = $paused->moduleActivity('7d', 'live')['totals'];
        self::assertIsArray($totals);
        self::assertArrayHasKey('liveUsers', $totals);
        self::assertNull($totals['liveUsers']);
    }

    public function test_last_successful_sign_in_fills_the_dashboard_when_activity_was_never_collected(): void
    {
        $logins = new InMemoryLastLoginAnalyticsRepository();
        $logins->record(1, new \DateTimeImmutable('2026-10-28T02:00:00Z'), true);
        $logins->record(2, new \DateTimeImmutable('2026-10-21T04:00:00Z'), false);
        $analytics = new ActivityAnalyticsService($this->store, $this->clock, false, $logins);

        $report = $analytics->activeUsers('30d');
        self::assertSame('last_login', $report['status']);
        self::assertSame('2026-10-21', $report['collectingSince']);
        self::assertIsArray($report['summary']);
        $summary = $report['summary'];
        self::assertSame(['total' => 1, 'web' => null, 'mobile' => null, 'staff' => 1, 'customer' => 0], $summary['today']);
        self::assertSame(1, $summary['liveNow']);
        $points = self::listOf($report['points']);
        self::assertSame(1, $points[count($points) - 1]['total']);
        self::assertNull($points[count($points) - 1]['web']);
        self::assertSame(1, $points[count($points) - 8]['total']);

        $live = $analytics->liveUsers();
        self::assertSame('last_login', $live['status']);
        self::assertSame(['total' => 1, 'web' => null, 'mobile' => null, 'staff' => 1, 'customer' => 0], $live['live']);
        self::assertSame([], $analytics->moduleActivity('today', 'live')['modules']);
    }

    public function test_live_counts_follow_heartbeats_even_when_collection_is_off(): void
    {
        $presence = new InMemoryLivePresenceStore();
        $recorder = new RecordActivityService($this->store, new ClockedActivityThrottle($this->clock), $this->clock, false, $presence);
        $analytics = new ActivityAnalyticsService($this->store, $this->clock, false, new InMemoryLastLoginAnalyticsRepository(), $presence);

        $recorder->record($this->user(1, ['EMPLOYEE']), $this->payload(1, 'web', 'products', 'view'), false);

        $live = $analytics->liveUsers()['live'];
        self::assertIsArray($live);
        self::assertSame(['total' => 1, 'web' => 1, 'mobile' => 0, 'staff' => 1, 'customer' => 0], $live);
        self::assertSame(1, $analytics->activeUsers('30d')['summary']['liveNow']);
    }

    public function test_history_survives_session_expiry_and_retention_removes_only_old_rows(): void
    {
        $this->signal($this->user(1, ['EMPLOYEE']), 1, 'web', 'products', 'view');
        $this->clock->advance('+31 days');

        $purge = new PurgeUserActivityService($this->store, $this->clock);
        $dryRun = $purge->purge(true);
        self::assertSame(['sessions' => 1, 'hourly' => 1, 'daily' => 0, 'moduleDaily' => 0], $dryRun['removed']);
        self::assertSame(['sessions' => 1, 'hourly' => 1, 'daily' => 0, 'moduleDaily' => 0], $purge->purge(false)['removed']);

        $points = $this->points('90d');
        self::assertSame(1, $points[count($points) - 32]['total'], 'Daily active users stay after the session is gone.');
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        $this->expectException(InvalidActivityRequest::class);
        $this->analytics->activeUsers('365d');
    }

    public function test_invalid_module_sort_is_rejected(): void
    {
        $this->expectException(InvalidActivityRequest::class);
        $this->analytics->moduleActivity('today', 'name');
    }

    private function rebuild(): void
    {
        $this->store = new InMemoryUserActivityRepository();
        $presence = new InMemoryLivePresenceStore();
        $this->recorder = new RecordActivityService($this->store, new ClockedActivityThrottle($this->clock), $this->clock, true, $presence);
        $this->analytics = new ActivityAnalyticsService($this->store, $this->clock, true, new InMemoryLastLoginAnalyticsRepository(), $presence);
    }

    /**
     * @param list<string> $roles
     */
    private function user(int $id, array $roles): AuthenticatedUser
    {
        return new AuthenticatedUser($id, sprintf('00000000-0000-4000-8000-%012d', $id), 'synthetic' . $id . '@example.test', 'Synthetic ' . $id, 'active', $roles, ['ACCOUNT.LOGIN']);
    }

    /**
     * @return array<string, string>
     */
    private function payload(int $session, string $platform, string $module, string $event): array
    {
        return [
            'sessionId' => sprintf('11111111-1111-4111-8111-%012d', $session),
            'platform' => $platform,
            'module' => $module,
            'event' => $event,
        ];
    }

    private function signal(AuthenticatedUser $user, int $session, string $platform, string $module, string $event): void
    {
        $this->recorder->record($user, $this->payload($session, $platform, $module, $event), false);
    }

    /**
     * @return array<string, mixed>
     */
    private function todaySummary(): array
    {
        $summary = $this->analytics->activeUsers('30d')['summary'];
        self::assertIsArray($summary);
        self::assertIsArray($summary['today']);
        /** @var array<string, mixed> $today */
        $today = $summary['today'];

        return $today;
    }

    /**
     * @return array<string, mixed>
     */
    private function liveTotals(): array
    {
        $live = $this->analytics->liveUsers()['live'];
        self::assertIsArray($live);
        /** @var array<string, mixed> $live */

        return $live;
    }

    /**
     * @return array<string, int>
     */
    private function liveModules(): array
    {
        $live = [];
        foreach ($this->modules('today', 'live') as $module) {
            if (is_int($module['liveUsers']) && $module['liveUsers'] > 0 && is_string($module['code'])) {
                $live[$module['code']] = $module['liveUsers'];
            }
        }

        return $live;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modules(string $range, string $sort): array
    {
        return self::listOf($this->analytics->moduleActivity($range, $sort)['modules']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function points(string $range): array
    {
        return self::listOf($this->analytics->activeUsers($range)['points']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        self::assertIsArray($value);
        $rows = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }
}
