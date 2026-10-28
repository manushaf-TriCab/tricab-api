<?php

declare(strict_types=1);

namespace TriCab\Api\Analytics\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Analytics\Application\DTO\ActivityWrite;
use TriCab\Core\Analytics\Contract\UserActivityRepository;

/**
 * [analytics] tables (database/scripts/development/create-user-activity-schema.sql).
 *
 * Every write is a single idempotent statement: MERGE ... WITH (HOLDLOCK) for rows that must exist once.
 * Times are UTC DATETIME2; reporting dates are Melbourne dates computed by the caller.
 */
final class AzureSqlUserActivityRepository implements UserActivityRepository
{
    private const UTC = 'Y-m-d H:i:s';

    public function __construct(private readonly SqlConnection $sql)
    {
    }

    public function record(ActivityWrite $write): void
    {
        $at = $write->atUtc->setTimezone(new DateTimeZone('UTC'))->format(self::UTC);
        $platform = $write->platform->value;
        $staff = $write->isStaff ? 1 : 0;

        if ($write->writeSession) {
            // A session id already owned by another user matches but fails the ownership test, so nothing changes.
            $this->sql->execute(<<<'SQL'
/*q:analytics.session*/
MERGE [analytics].[ActivitySessions] WITH (HOLDLOCK) AS t
USING (SELECT CAST(:sessionId AS UNIQUEIDENTIFIER) AS SessionId) AS s
    ON t.[SessionId] = s.SessionId
WHEN MATCHED AND t.[UserId] = :userId THEN UPDATE SET
    [ModuleCode] = :moduleCode,
    [State] = :state,
    [IsStaff] = :isStaff,
    [LastSignalAtUtc] = :signalAt,
    [LastActivityAtUtc] = CASE WHEN :qualifying = 1 THEN :activityAt ELSE t.[LastActivityAtUtc] END,
    [EndedAtUtc] = CASE WHEN :ended = 1 THEN :endedAt ELSE NULL END
WHEN NOT MATCHED THEN INSERT
    ([SessionId], [UserId], [Platform], [IsStaff], [ModuleCode], [State], [StartedAtUtc], [LastActivityAtUtc], [LastSignalAtUtc], [EndedAtUtc])
    VALUES (s.SessionId, :insertUserId, :platform, :insertIsStaff, :insertModuleCode, :insertState, :startedAt, :insertActivityAt, :insertSignalAt,
        CASE WHEN :insertEnded = 1 THEN :insertEndedAt ELSE NULL END);
SQL, [
                ':sessionId' => $write->sessionId,
                ':userId' => $write->userId,
                ':moduleCode' => $write->moduleCode,
                ':state' => $write->sessionState(),
                ':isStaff' => $staff,
                ':signalAt' => $at,
                ':qualifying' => $write->type->isQualifying() ? 1 : 0,
                ':activityAt' => $at,
                ':ended' => $write->sessionState() === 'ended' ? 1 : 0,
                ':endedAt' => $at,
                ':insertUserId' => $write->userId,
                ':platform' => $platform,
                ':insertIsStaff' => $staff,
                ':insertModuleCode' => $write->moduleCode,
                ':insertState' => $write->sessionState(),
                ':startedAt' => $at,
                ':insertActivityAt' => $at,
                ':insertSignalAt' => $at,
                ':insertEnded' => $write->sessionState() === 'ended' ? 1 : 0,
                ':insertEndedAt' => $at,
            ]);
        }

        if ($write->writeDaily) {
            $this->sql->execute(<<<'SQL'
/*q:analytics.daily*/
MERGE [analytics].[DailyUserActivity] WITH (HOLDLOCK) AS t
USING (SELECT CAST(:activityDate AS DATE) AS ActivityDate, :platform AS Platform, CAST(:userId AS BIGINT) AS UserId) AS s
    ON t.[ActivityDate] = s.ActivityDate AND t.[Platform] = s.Platform AND t.[UserId] = s.UserId
WHEN NOT MATCHED THEN INSERT ([ActivityDate], [Platform], [UserId], [IsStaff], [FirstSeenAtUtc])
    VALUES (s.ActivityDate, s.Platform, s.UserId, :isStaff, :firstSeenAt);
SQL, [
                ':activityDate' => $write->localDate,
                ':platform' => $platform,
                ':userId' => $write->userId,
                ':isStaff' => $staff,
                ':firstSeenAt' => $at,
            ]);
        }

        if ($write->writeHourly) {
            $this->sql->execute(<<<'SQL'
/*q:analytics.hourly*/
MERGE [analytics].[HourlyUserActivity] WITH (HOLDLOCK) AS t
USING (SELECT CAST(:hourStart AS DATETIME2(0)) AS HourStartUtc, :platform AS Platform, CAST(:userId AS BIGINT) AS UserId) AS s
    ON t.[HourStartUtc] = s.HourStartUtc AND t.[Platform] = s.Platform AND t.[UserId] = s.UserId
WHEN NOT MATCHED THEN INSERT ([HourStartUtc], [Platform], [UserId], [IsStaff])
    VALUES (s.HourStartUtc, s.Platform, s.UserId, :isStaff);
SQL, [
                ':hourStart' => $write->hourStartUtc->setTimezone(new DateTimeZone('UTC'))->format(self::UTC),
                ':platform' => $platform,
                ':userId' => $write->userId,
                ':isStaff' => $staff,
            ]);
        }

        if ($write->writeModuleDaily) {
            $this->sql->execute(<<<'SQL'
/*q:analytics.module_daily*/
MERGE [analytics].[DailyModuleActivity] WITH (HOLDLOCK) AS t
USING (SELECT CAST(:activityDate AS DATE) AS ActivityDate, :moduleCode AS ModuleCode, :platform AS Platform, CAST(:userId AS BIGINT) AS UserId) AS s
    ON t.[ActivityDate] = s.ActivityDate AND t.[ModuleCode] = s.ModuleCode AND t.[Platform] = s.Platform AND t.[UserId] = s.UserId
WHEN MATCHED AND :increment > 0 THEN UPDATE SET [EventCount] = t.[EventCount] + :matchedIncrement
WHEN NOT MATCHED THEN INSERT ([ActivityDate], [ModuleCode], [Platform], [UserId], [IsStaff], [EventCount])
    VALUES (s.ActivityDate, s.ModuleCode, s.Platform, s.UserId, :isStaff, :insertIncrement);
SQL, [
                ':activityDate' => $write->localDate,
                ':moduleCode' => $write->moduleCode,
                ':platform' => $platform,
                ':userId' => $write->userId,
                ':increment' => $write->moduleViewIncrement(),
                ':matchedIncrement' => $write->moduleViewIncrement(),
                ':isStaff' => $staff,
                ':insertIncrement' => $write->moduleViewIncrement(),
            ]);
        }
    }

    public function liveTotals(DateTimeImmutable $activeSinceUtc): array
    {
        $rows = $this->sql->fetchAll(<<<'SQL'
/*q:analytics.live_totals*/
SELECT
    COUNT(DISTINCT [UserId]) AS total,
    COUNT(DISTINCT CASE WHEN [Platform] = 'web' THEN [UserId] END) AS web,
    COUNT(DISTINCT CASE WHEN [Platform] = 'mobile' THEN [UserId] END) AS mobile,
    COUNT(DISTINCT CASE WHEN [IsStaff] = 1 THEN [UserId] END) AS staff,
    COUNT(DISTINCT CASE WHEN [IsStaff] = 0 THEN [UserId] END) AS customer
FROM [analytics].[ActivitySessions]
WHERE [State] = 'active' AND [LastActivityAtUtc] >= :since
SQL, [':since' => self::utc($activeSinceUtc)]);

        return self::counts($rows[0] ?? []);
    }

    public function liveByModule(DateTimeImmutable $activeSinceUtc): array
    {
        $rows = $this->sql->fetchAll(<<<'SQL'
/*q:analytics.live_by_module*/
WITH latest AS (
    SELECT [UserId], [ModuleCode],
        ROW_NUMBER() OVER (PARTITION BY [UserId] ORDER BY [LastActivityAtUtc] DESC, [LastSignalAtUtc] DESC, [Id] DESC) AS rn
    FROM [analytics].[ActivitySessions]
    WHERE [State] = 'active' AND [LastActivityAtUtc] >= :since
)
SELECT [ModuleCode] AS module, COUNT(*) AS users
FROM latest
WHERE rn = 1
GROUP BY [ModuleCode]
SQL, [':since' => self::utc($activeSinceUtc)]);

        $byModule = [];
        foreach ($rows as $row) {
            $byModule[self::str($row['module'] ?? '')] = self::int($row['users'] ?? 0);
        }

        return $byModule;
    }

    public function dailyActiveUsers(string $fromDate, string $toDate): array
    {
        $rows = $this->sql->fetchAll(<<<'SQL'
/*q:analytics.daily_active*/
SELECT
    CONVERT(CHAR(10), [ActivityDate], 23) AS bucket,
    COUNT(DISTINCT [UserId]) AS total,
    COUNT(DISTINCT CASE WHEN [Platform] = 'web' THEN [UserId] END) AS web,
    COUNT(DISTINCT CASE WHEN [Platform] = 'mobile' THEN [UserId] END) AS mobile,
    COUNT(DISTINCT CASE WHEN [IsStaff] = 1 THEN [UserId] END) AS staff,
    COUNT(DISTINCT CASE WHEN [IsStaff] = 0 THEN [UserId] END) AS customer
FROM [analytics].[DailyUserActivity]
WHERE [ActivityDate] >= :fromDate AND [ActivityDate] <= :toDate
GROUP BY [ActivityDate]
ORDER BY [ActivityDate]
SQL, [':fromDate' => $fromDate, ':toDate' => $toDate]);

        return array_map(static fn (array $row): array => ['bucket' => self::str($row['bucket'] ?? '')] + self::counts($row), $rows);
    }

    public function hourlyActiveUsers(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $rows = $this->sql->fetchAll(<<<'SQL'
/*q:analytics.hourly_active*/
SELECT
    CONVERT(CHAR(19), [HourStartUtc], 126) AS bucket,
    COUNT(DISTINCT [UserId]) AS total,
    COUNT(DISTINCT CASE WHEN [Platform] = 'web' THEN [UserId] END) AS web,
    COUNT(DISTINCT CASE WHEN [Platform] = 'mobile' THEN [UserId] END) AS mobile,
    COUNT(DISTINCT CASE WHEN [IsStaff] = 1 THEN [UserId] END) AS staff,
    COUNT(DISTINCT CASE WHEN [IsStaff] = 0 THEN [UserId] END) AS customer
FROM [analytics].[HourlyUserActivity]
WHERE [HourStartUtc] >= :fromUtc AND [HourStartUtc] <= :toUtc
GROUP BY [HourStartUtc]
ORDER BY [HourStartUtc]
SQL, [':fromUtc' => self::utc($fromUtc), ':toUtc' => self::utc($toUtc)]);

        return array_map(static fn (array $row): array => ['bucket' => self::str($row['bucket'] ?? '')] + self::counts($row), $rows);
    }

    public function moduleActivity(string $fromDate, string $toDate): array
    {
        $rows = $this->sql->fetchAll(<<<'SQL'
/*q:analytics.module_activity*/
SELECT
    [ModuleCode] AS module,
    COUNT(DISTINCT [UserId]) AS users,
    COUNT(DISTINCT CASE WHEN [Platform] = 'web' THEN [UserId] END) AS webUsers,
    COUNT(DISTINCT CASE WHEN [Platform] = 'mobile' THEN [UserId] END) AS mobileUsers,
    SUM(CAST([EventCount] AS BIGINT)) AS events
FROM [analytics].[DailyModuleActivity]
WHERE [ActivityDate] >= :fromDate AND [ActivityDate] <= :toDate
GROUP BY [ModuleCode]
SQL, [':fromDate' => $fromDate, ':toDate' => $toDate]);

        return array_map(static fn (array $row): array => [
            'module' => self::str($row['module'] ?? ''),
            'users' => self::int($row['users'] ?? 0),
            'webUsers' => self::int($row['webUsers'] ?? 0),
            'mobileUsers' => self::int($row['mobileUsers'] ?? 0),
            'events' => self::int($row['events'] ?? 0),
        ], $rows);
    }

    public function firstActivityDate(): ?string
    {
        try {
            $rows = $this->sql->fetchAll(
                '/*q:analytics.first_date*/ SELECT CONVERT(CHAR(10), MIN([ActivityDate]), 23) AS firstDate FROM [analytics].[DailyUserActivity]',
            );
        } catch (DatabaseUnavailable $exception) {
            // Schema is not on this database yet: treat as never collected, not as an outage.
            if ($exception->isInvalidObjectReference()) {
                return null;
            }

            throw $exception;
        }

        $first = $rows[0]['firstDate'] ?? null;

        return is_string($first) && trim($first) !== '' ? trim($first) : null;
    }

    public function purge(
        DateTimeImmutable $sessionsBeforeUtc,
        DateTimeImmutable $hourlyBeforeUtc,
        string $dailyBeforeDate,
        bool $dryRun,
    ): array {
        $targets = [
            'sessions' => ['[analytics].[ActivitySessions]', '[LastSignalAtUtc]', self::utc($sessionsBeforeUtc)],
            'hourly' => ['[analytics].[HourlyUserActivity]', '[HourStartUtc]', self::utc($hourlyBeforeUtc)],
            'daily' => ['[analytics].[DailyUserActivity]', '[ActivityDate]', $dailyBeforeDate],
            'moduleDaily' => ['[analytics].[DailyModuleActivity]', '[ActivityDate]', $dailyBeforeDate],
        ];

        $removed = ['sessions' => 0, 'hourly' => 0, 'daily' => 0, 'moduleDaily' => 0];
        foreach ($targets as $name => [$table, $column, $cutoff]) {
            $count = $this->sql->fetchAll(
                sprintf('SELECT COUNT_BIG(*) AS n FROM %s WHERE %s < :cutoff', $table, $column),
                [':cutoff' => $cutoff],
            );
            $removed[$name] = self::int($count[0]['n'] ?? 0);
            if (!$dryRun && $removed[$name] > 0) {
                $this->sql->execute(sprintf('DELETE FROM %s WHERE %s < :cutoff', $table, $column), [':cutoff' => $cutoff]);
            }
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{total: int, web: int, mobile: int, staff: int, customer: int}
     */
    private static function counts(array $row): array
    {
        return [
            'total' => self::int($row['total'] ?? 0),
            'web' => self::int($row['web'] ?? 0),
            'mobile' => self::int($row['mobile'] ?? 0),
            'staff' => self::int($row['staff'] ?? 0),
            'customer' => self::int($row['customer'] ?? 0),
        ];
    }

    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(self::UTC);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
