<?php

declare(strict_types=1);

namespace TriCab\Api\Analytics\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Analytics\Contract\LastLoginAnalyticsRepository;

/**
 * identity.Users.LastSuccessfulLoginAt — one overwrite per user, Melbourne reporting days.
 * Platform is unknown; web and mobile are therefore unmeasured.
 */
final class AzureSqlLastLoginAnalyticsRepository implements LastLoginAnalyticsRepository
{
    private const MELBOURNE_DATE = "CONVERT(CHAR(10), CAST(u.[LastSuccessfulLoginAt] AT TIME ZONE 'AUS Eastern Standard Time' AS datetime2), 23)";
    private const UTC_HOUR = "CONVERT(CHAR(13), CAST(u.[LastSuccessfulLoginAt] AT TIME ZONE 'UTC' AS datetime2), 126) + ':00:00'";

    private const STAFF = <<<'SQL'
CAST(CASE
    WHEN roles.HasCustomer = 1 THEN 0
    WHEN roles.HasStaff = 1 THEN 1
    ELSE 0
END AS INT)
SQL;

    private const FROM_USERS = <<<'SQL'
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] st ON st.[UserStatusId] = u.[UserStatusId] AND st.[AllowsLogin] = 1
OUTER APPLY (
    SELECT
        CAST(MAX(CASE WHEN r.[RoleCode] IN (N'EMPLOYEE', N'ADMINISTRATOR') THEN 1 ELSE 0 END) AS INT) AS HasStaff,
        CAST(MAX(CASE
            WHEN r.[RoleCode] IN (N'CUSTOMER_USER', N'CUSTOMER_ADMIN', N'CUSTOMER_APPLICANT', N'NEWBIE') THEN 1
            WHEN r.[RoleCode] LIKE N'CUSTOMER_%' AND r.[RoleCode] NOT LIKE N'CUSTOMER_CONTACT_%' THEN 1
            WHEN r.[RoleCode] LIKE N'PORTAL_%' THEN 1
            ELSE 0
        END) AS INT) AS HasCustomer
    FROM [identity].[UserRoles] ur
    INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
    WHERE ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
) roles
WHERE u.[LastSuccessfulLoginAt] IS NOT NULL
SQL;

    public function __construct(private readonly SqlConnection $sql)
    {
    }

    public function firstLoginDate(): ?string
    {
        $rows = $this->sql->fetchAll(
            '/*q:analytics.last_login.first*/ SELECT MIN(' . self::MELBOURNE_DATE . ') AS firstDate ' . self::FROM_USERS,
        );
        $first = $rows[0]['firstDate'] ?? null;

        return is_string($first) && trim($first) !== '' ? trim($first) : null;
    }

    public function liveTotals(DateTimeImmutable $sinceUtc): array
    {
        $rows = $this->sql->fetchAll(
            '/*q:analytics.last_login.live*/ SELECT
                COUNT_BIG(*) AS total,
                SUM(' . self::STAFF . ') AS staff
            ' . self::FROM_USERS . '
              AND u.[LastSuccessfulLoginAt] >= :since',
            [':since' => $sinceUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );

        return self::totals($rows[0] ?? []);
    }

    public function dailyLastLogins(string $fromDate, string $toDate): array
    {
        $rows = $this->sql->fetchAll(
            '/*q:analytics.last_login.daily*/ SELECT
                ' . self::MELBOURNE_DATE . ' AS bucket,
                COUNT_BIG(*) AS total,
                SUM(' . self::STAFF . ') AS staff
            ' . self::FROM_USERS . '
              AND ' . self::MELBOURNE_DATE . ' >= :fromDate
              AND ' . self::MELBOURNE_DATE . ' <= :toDate
            GROUP BY ' . self::MELBOURNE_DATE . '
            ORDER BY ' . self::MELBOURNE_DATE,
            [':fromDate' => $fromDate, ':toDate' => $toDate],
        );

        return array_map(self::row(...), $rows);
    }

    public function hourlyLastLogins(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $rows = $this->sql->fetchAll(
            '/*q:analytics.last_login.hourly*/ SELECT
                ' . self::UTC_HOUR . ' AS bucket,
                COUNT_BIG(*) AS total,
                SUM(' . self::STAFF . ') AS staff
            ' . self::FROM_USERS . '
              AND CAST(u.[LastSuccessfulLoginAt] AT TIME ZONE \'UTC\' AS datetime2) >= :fromUtc
              AND CAST(u.[LastSuccessfulLoginAt] AT TIME ZONE \'UTC\' AS datetime2) <= :toUtc
            GROUP BY ' . self::UTC_HOUR . '
            ORDER BY ' . self::UTC_HOUR,
            [
                ':fromUtc' => $fromUtc->format('Y-m-d H:i:s'),
                ':toUtc' => $toUtc->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{bucket: string, web: null, mobile: null, total: int, staff: int, customer: int}
     */
    private static function row(array $row): array
    {
        $totals = self::totals($row);

        return [
            'bucket' => trim((string) ($row['bucket'] ?? '')),
            'web' => null,
            'mobile' => null,
            'total' => $totals['total'],
            'staff' => $totals['staff'],
            'customer' => $totals['customer'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{total: int, web: null, mobile: null, staff: int, customer: int}
     */
    private static function totals(array $row): array
    {
        $total = self::int($row['total'] ?? 0);
        $staff = self::int($row['staff'] ?? 0);

        return [
            'total' => $total,
            'web' => null,
            'mobile' => null,
            'staff' => $staff,
            'customer' => max(0, $total - $staff),
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
