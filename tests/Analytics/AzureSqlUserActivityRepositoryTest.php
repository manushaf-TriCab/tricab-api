<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Analytics;

use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Analytics\Persistence\AzureSqlUserActivityRepository;
use TriCab\Api\Analytics\Persistence\CachingUserActivityRepository;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Tests\Doubles\ClockedActivityThrottle;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Analytics\Application\DTO\ActivityWrite;
use TriCab\Core\Analytics\Application\Service\RecordActivityService;
use TriCab\Core\Analytics\Domain\ActivityPlatform;
use TriCab\Core\Analytics\Domain\ActivitySignalType;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

final class AzureSqlUserActivityRepositoryTest extends TestCase
{
    public function test_a_first_view_writes_session_day_hour_and_module_with_bound_parameters(): void
    {
        $sql = new FakeSqlConnection();
        (new AzureSqlUserActivityRepository($sql))->record($this->write(ActivitySignalType::View, true, true, true, true));

        self::assertCount(4, $sql->calls);
        self::assertStringContainsString('[analytics].[ActivitySessions]', $sql->calls[0]['sql']);
        self::assertStringContainsString('t.[UserId] = :userId', $sql->calls[0]['sql']);
        self::assertSame('11111111-1111-4111-8111-000000000001', $sql->calls[0]['params'][':sessionId']);
        self::assertSame('active', $sql->calls[0]['params'][':state']);
        self::assertSame('2026-10-28 02:00:00', $sql->calls[0]['params'][':activityAt']);
        self::assertSame('[analytics].[DailyUserActivity]', self::table($sql->calls[1]['sql']));
        self::assertSame('2026-10-28', $sql->calls[1]['params'][':activityDate']);
        self::assertSame('[analytics].[HourlyUserActivity]', self::table($sql->calls[2]['sql']));
        self::assertSame('2026-10-28 02:00:00', $sql->calls[2]['params'][':hourStart']);
        self::assertSame('[analytics].[DailyModuleActivity]', self::table($sql->calls[3]['sql']));
        self::assertSame(1, $sql->calls[3]['params'][':increment']);

        foreach ($sql->calls as $call) {
            self::assertStringNotContainsString('synthetic', $call['sql'], 'Values are bound, never interpolated.');
            self::assertStringNotContainsString('2026-10-28', $call['sql']);
        }
    }

    public function test_only_the_parts_that_still_need_writing_are_written(): void
    {
        $sql = new FakeSqlConnection();
        (new AzureSqlUserActivityRepository($sql))->record($this->write(ActivitySignalType::Background, true, false, false, false));

        self::assertCount(1, $sql->calls);
        self::assertSame('background', $sql->calls[0]['params'][':state']);
        self::assertSame(0, $sql->calls[0]['params'][':qualifying']);
    }

    public function test_repeat_heartbeats_are_suppressed_until_the_refresh_window_passes(): void
    {
        $clock = FixedClock::utc('2026-10-28T02:00:00Z');
        $sql = new FakeSqlConnection();
        $service = new RecordActivityService(new AzureSqlUserActivityRepository($sql), new ClockedActivityThrottle($clock), $clock, true);
        $user = new AuthenticatedUser(7, '00000000-0000-4000-8000-000000000007', 'synthetic@example.test', 'Synthetic', 'active', ['EMPLOYEE'], []);
        $payload = ['sessionId' => '11111111-1111-4111-8111-000000000001', 'platform' => 'web', 'module' => 'products'];

        $service->record($user, $payload + ['event' => 'view'], false);
        self::assertCount(4, $sql->calls);

        $clock->advance('+10 seconds');
        $service->record($user, $payload + ['event' => 'heartbeat'], false);
        self::assertCount(4, $sql->calls, 'Nothing new to record within the refresh window.');

        $clock->advance('+50 seconds');
        $service->record($user, $payload + ['event' => 'heartbeat'], false);
        self::assertCount(5, $sql->calls, 'Only the session is refreshed; the day, hour and module are already recorded.');
        self::assertStringContainsString('[analytics].[ActivitySessions]', $sql->calls[4]['sql']);
    }

    public function test_reads_map_aggregate_rows_and_never_select_identities(): void
    {
        $sql = new FakeSqlConnection();
        $sql->queueFetch([['total' => '3', 'web' => '2', 'mobile' => '1', 'staff' => '2', 'customer' => '1']]);
        $sql->queueFetch([['module' => 'products', 'users' => '2']]);
        $sql->queueFetch([['bucket' => '2026-10-28', 'total' => 3, 'web' => 2, 'mobile' => 1, 'staff' => 2, 'customer' => 1]]);
        $sql->queueFetch([['module' => 'finance', 'users' => 1, 'webUsers' => 1, 'mobileUsers' => 0, 'events' => '4']]);
        $sql->queueFetch([['firstDate' => '2026-10-20']]);
        $repo = new AzureSqlUserActivityRepository($sql);
        $since = new DateTimeImmutable('2026-10-28T01:58:00Z');

        self::assertSame(['total' => 3, 'web' => 2, 'mobile' => 1, 'staff' => 2, 'customer' => 1], $repo->liveTotals($since));
        self::assertSame(['products' => 2], $repo->liveByModule($since));
        self::assertSame([['bucket' => '2026-10-28', 'total' => 3, 'web' => 2, 'mobile' => 1, 'staff' => 2, 'customer' => 1]], $repo->dailyActiveUsers('2026-10-22', '2026-10-28'));
        self::assertSame([['module' => 'finance', 'users' => 1, 'webUsers' => 1, 'mobileUsers' => 0, 'events' => 4]], $repo->moduleActivity('2026-10-28', '2026-10-28'));
        self::assertSame('2026-10-20', $repo->firstActivityDate());

        self::assertSame('2026-10-28 01:58:00', $sql->calls[0]['params'][':since']);
        self::assertStringContainsString("[State] = 'active'", $sql->calls[0]['sql']);
        self::assertStringContainsString('PARTITION BY [UserId]', $sql->calls[1]['sql']);
        foreach ($sql->calls as $call) {
            self::assertDoesNotMatchRegularExpression('/SELECT\s+\[UserId\]\s*,?\s*FROM|Email|DisplayName|PublicId/i', $call['sql']);
        }
    }

    public function test_purge_dry_run_counts_without_deleting(): void
    {
        $sql = new FakeSqlConnection([['n' => 5]]);
        $removed = (new AzureSqlUserActivityRepository($sql))->purge(
            new DateTimeImmutable('2026-09-28T00:00:00Z'),
            new DateTimeImmutable('2026-10-14T00:00:00Z'),
            '2025-09-23',
            true,
        );

        self::assertSame(['sessions' => 5, 'hourly' => 5, 'daily' => 5, 'moduleDaily' => 5], $removed);
        self::assertCount(4, $sql->calls);
        foreach ($sql->calls as $call) {
            self::assertStringStartsWith('SELECT COUNT_BIG(*)', $call['sql']);
        }

        $sql = new FakeSqlConnection([['n' => 2]]);
        (new AzureSqlUserActivityRepository($sql))->purge(
            new DateTimeImmutable('2026-09-28T00:00:00Z'),
            new DateTimeImmutable('2026-10-14T00:00:00Z'),
            '2025-09-23',
            false,
        );
        self::assertCount(8, $sql->calls);
        self::assertStringStartsWith('DELETE FROM [analytics].[ActivitySessions] WHERE [LastSignalAtUtc] < :cutoff', $sql->calls[1]['sql']);
    }

    public function test_a_missing_activity_schema_is_unread_history_not_an_outage(): void
    {
        $driver = new PDOException("Invalid object name 'analytics.DailyUserActivity'.");
        $driver->errorInfo = ['42S02', 208, "Invalid object name 'analytics.DailyUserActivity'."];
        $sql = new FakeSqlConnection();
        $sql->failNextFetch = DatabaseUnavailable::fromThrowable($driver);

        self::assertNull((new AzureSqlUserActivityRepository($sql))->firstActivityDate());
        self::assertStringContainsString('[analytics].[DailyUserActivity]', $sql->calls[0]['sql']);
    }

    public function test_completed_days_are_cached_but_today_and_live_figures_are_not(): void
    {
        $sql = new FakeSqlConnection([]);
        $clock = FixedClock::utc('2026-10-28T02:00:00Z');
        $repo = new CachingUserActivityRepository(new AzureSqlUserActivityRepository($sql), new ArrayApplicationCache(), $clock);

        $repo->dailyActiveUsers('2026-10-01', '2026-10-27');
        $repo->dailyActiveUsers('2026-10-01', '2026-10-27');
        self::assertCount(1, $sql->calls);

        $repo->dailyActiveUsers('2026-10-28', '2026-10-28');
        $repo->dailyActiveUsers('2026-10-28', '2026-10-28');
        $repo->liveTotals(new DateTimeImmutable('2026-10-28T01:58:00Z'));
        $repo->liveTotals(new DateTimeImmutable('2026-10-28T01:58:00Z'));
        self::assertCount(5, $sql->calls);

        $repo->firstActivityDate();
        $repo->firstActivityDate();
        self::assertCount(7, $sql->calls, 'An empty store is re-read until something is recorded.');
    }

    private function write(ActivitySignalType $type, bool $session, bool $daily, bool $hourly, bool $module): ActivityWrite
    {
        $at = new DateTimeImmutable('2026-10-28T02:00:00Z');

        return new ActivityWrite(
            userId: 7,
            isStaff: true,
            platform: ActivityPlatform::Web,
            sessionId: '11111111-1111-4111-8111-000000000001',
            moduleCode: 'products',
            type: $type,
            atUtc: $at,
            localDate: '2026-10-28',
            hourStartUtc: $at,
            writeSession: $session,
            writeDaily: $daily,
            writeHourly: $hourly,
            writeModuleDaily: $module,
        );
    }

    private static function table(string $sql): string
    {
        if (preg_match('/MERGE (\[analytics\]\.\[\w+\])/', $sql, $match) !== 1) {
            self::fail('Expected a MERGE into an analytics table.');
        }

        return $match[1];
    }
}
