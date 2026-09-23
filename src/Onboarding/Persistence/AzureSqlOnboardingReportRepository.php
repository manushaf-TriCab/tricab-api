<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Application\DTO\Report\ItemComplianceRow;
use TriCab\Core\Onboarding\Application\DTO\Report\OnboardingReportSummary;
use TriCab\Core\Onboarding\Application\DTO\Report\OverdueWorkRow;
use TriCab\Core\Onboarding\Contract\OnboardingReportRepository;
use TriCab\Core\Onboarding\Domain\Service\OverduePolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * The HR reports (4.5), against `tricab-mel`.
 *
 * Reads `[onboarding]` and the display name from `[identity].[Users]`, and
 * nothing else: no `[onboarding_hr]`, no email address, no score, no note.
 * `OnboardingReportRepositoryBoundaryTest` reads this file to hold that line.
 *
 * Every named parameter appears once per statement — PDO_SQLSRV leaves a
 * repeated one unbound — so a statement that needs "has not left" or "overdue"
 * in a second place must give the second occurrence its own name.
 */
final class AzureSqlOnboardingReportRepository implements OnboardingReportRepository
{
    /** Item id, public id and title for whichever of the three versions an assignment points at. */
    private const ITEM_JOINS = <<<'SQL'
        LEFT JOIN [onboarding].[TrainingModuleVersions] tmv ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
        LEFT JOIN [onboarding].[TrainingModules] tm ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
        LEFT JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
        LEFT JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
        LEFT JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
        LEFT JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
        SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function summary(DateTimeImmutable $nowUtc, string $today): OnboardingReportSummary
    {
        $completed = "N'" . AssignmentStatus::COMPLETED . "'";
        $people = $this->connection->fetchAll(
            <<<SQL
            SELECT
                COUNT(CASE WHEN p.[IsCurrent] = 1 THEN 1 END) AS [Current],
                COUNT(CASE WHEN p.[IsCurrent] = 0 THEN 1 END) AS [Departed],
                COUNT(CASE WHEN p.[IsCurrent] = 1 AND p.[InvitedAtUtc] IS NULL THEN 1 END) AS [NotYetInvited],
                COUNT(CASE WHEN p.[IsCurrent] = 1 AND p.[Live] = 0 THEN 1 END) AS [NothingAllocated],
                COUNT(CASE WHEN p.[IsCurrent] = 1 AND p.[Live] > 0 AND p.[Live] = p.[Done] THEN 1 END) AS [Finished]
            FROM (
                SELECT
                    t.[InvitedAtUtc],
                    CASE WHEN t.[DepartedAtUtc] IS NULL OR t.[DepartedAtUtc] > CONVERT(DATETIME2(7), :now) THEN 1 ELSE 0 END AS [IsCurrent],
                    (SELECT COUNT(*) FROM [onboarding].[Assignments] a
                     WHERE a.[UserId] = t.[UserId] AND a.[WithdrawnAtUtc] IS NULL) AS [Live],
                    (SELECT COUNT(*) FROM [onboarding].[Assignments] a
                     WHERE a.[UserId] = t.[UserId] AND a.[WithdrawnAtUtc] IS NULL AND a.[Status] = {$completed}) AS [Done]
                FROM [onboarding].[TraineeProfiles] t
            ) p
            SQL,
            [':now' => AzureSqlValueMapper::formatDateTime($nowUtc)],
        );

        $overdue = self::overdueCondition('a');
        $work = $this->connection->fetchAll(
            <<<SQL
            SELECT a.[Status], COUNT(*) AS [Count],
                   COUNT(CASE WHEN {$overdue} THEN 1 END) AS [Overdue]
            FROM [onboarding].[Assignments] a
            JOIN [onboarding].[TraineeProfiles] t ON t.[UserId] = a.[UserId]
            WHERE a.[WithdrawnAtUtc] IS NULL
              AND (t.[DepartedAtUtc] IS NULL OR t.[DepartedAtUtc] > CONVERT(DATETIME2(7), :now))
            GROUP BY a.[Status]
            SQL,
            [':now' => AzureSqlValueMapper::formatDateTime($nowUtc), ':today' => $today],
        );

        // Exactly the reviewer queue's WHERE clause, so the overview's number
        // is the number of rows a reviewer finds.
        $queue = $this->connection->fetchAll(
            <<<'SQL'
            SELECT a.[Status], COUNT(*) AS [Count]
            FROM [onboarding].[Assignments] a
            WHERE a.[WithdrawnAtUtc] IS NULL AND a.[Status] IN (N'submitted', N'need_help')
            GROUP BY a.[Status]
            SQL,
        );

        $byStatus = [];
        $overdueCount = 0;
        foreach ($work as $row) {
            $code = AssignmentStatus::fromStorage($row['Status'] ?? null)->toString();
            $byStatus[$code] = AzureSqlValueMapper::intValue($row['Count'] ?? 0);
            $overdueCount += AzureSqlValueMapper::intValue($row['Overdue'] ?? 0);
        }
        $queued = [];
        foreach ($queue as $row) {
            $queued[AssignmentStatus::fromStorage($row['Status'] ?? null)->toString()] = AzureSqlValueMapper::intValue($row['Count'] ?? 0);
        }
        $p = $people[0] ?? [];

        return new OnboardingReportSummary(
            $today,
            AzureSqlValueMapper::intValue($p['Current'] ?? 0),
            AzureSqlValueMapper::intValue($p['Departed'] ?? 0),
            AzureSqlValueMapper::intValue($p['NotYetInvited'] ?? 0),
            AzureSqlValueMapper::intValue($p['NothingAllocated'] ?? 0),
            AzureSqlValueMapper::intValue($p['Finished'] ?? 0),
            $byStatus,
            $overdueCount,
            $queued[AssignmentStatus::SUBMITTED] ?? 0,
            $queued[AssignmentStatus::NEED_HELP] ?? 0,
        );
    }

    public function itemCompliance(DateTimeImmutable $nowUtc, string $today, int $page, int $pageSize): array
    {
        $overdue = self::overdueCondition('w');
        $joins = self::ITEM_JOINS;
        $inProgress = self::statusList([AssignmentStatus::inProgress(), AssignmentStatus::needHelp(), AssignmentStatus::fixAndResubmit()]);

        $query = static fn (int $offset, int $fetch): array => [
            <<<SQL
            WITH [Items] AS (
                SELECT N'training' AS [ItemType], m.[TrainingModuleId] AS [ItemId], m.[PublicId], m.[Title],
                       CASE WHEN m.[IsActive] = 1 AND EXISTS (
                           SELECT 1 FROM [onboarding].[TrainingModuleVersions] v
                           WHERE v.[TrainingModuleId] = m.[TrainingModuleId] AND v.[IsPublished] = 1 AND v.[RetiredAtUtc] IS NULL
                       ) THEN 1 ELSE 0 END AS [Offered]
                FROM [onboarding].[TrainingModules] m
                UNION ALL
                SELECT N'policy', p.[PolicyId], p.[PublicId], p.[Title],
                       CASE WHEN p.[IsActive] = 1 AND EXISTS (
                           SELECT 1 FROM [onboarding].[PolicyVersions] v
                           WHERE v.[PolicyId] = p.[PolicyId] AND v.[IsPublished] = 1 AND v.[SupersededAtUtc] IS NULL
                       ) THEN 1 ELSE 0 END
                FROM [onboarding].[Policies] p
                UNION ALL
                SELECT N'form', f.[FormDefinitionId], f.[PublicId], f.[Title],
                       CASE WHEN f.[IsActive] = 1 AND EXISTS (
                           SELECT 1 FROM [onboarding].[FormDefinitionVersions] v
                           WHERE v.[FormDefinitionId] = f.[FormDefinitionId] AND v.[IsPublished] = 1 AND v.[RetiredAtUtc] IS NULL
                       ) THEN 1 ELSE 0 END
                FROM [onboarding].[FormDefinitions] f
            ),
            [Work] AS (
                SELECT a.[ItemType],
                       COALESCE(tm.[TrainingModuleId], p.[PolicyId], fd.[FormDefinitionId]) AS [ItemId],
                       a.[Status], a.[DueDate]
                FROM [onboarding].[Assignments] a
                JOIN [onboarding].[TraineeProfiles] t ON t.[UserId] = a.[UserId]
                {$joins}
                WHERE a.[WithdrawnAtUtc] IS NULL
                  AND (t.[DepartedAtUtc] IS NULL OR t.[DepartedAtUtc] > CONVERT(DATETIME2(7), :now))
            ),
            [Rolled] AS (
                SELECT i.[ItemType], i.[PublicId], i.[Title], i.[Offered],
                       COUNT(w.[Status]) AS [Assigned],
                       COUNT(CASE WHEN w.[Status] = N'completed' THEN 1 END) AS [Completed],
                       COUNT(CASE WHEN w.[Status] = N'submitted' THEN 1 END) AS [WaitingOnReview],
                       COUNT(CASE WHEN w.[Status] IN ({$inProgress}) THEN 1 END) AS [InProgress],
                       COUNT(CASE WHEN w.[Status] = N'not_started' THEN 1 END) AS [NotStarted],
                       COUNT(CASE WHEN {$overdue} THEN 1 END) AS [Overdue]
                FROM [Items] i
                LEFT JOIN [Work] w ON w.[ItemType] = i.[ItemType] AND w.[ItemId] = i.[ItemId]
                GROUP BY i.[ItemType], i.[PublicId], i.[Title], i.[Offered]
            )
            SELECT r.[ItemType], CONVERT(NVARCHAR(36), r.[PublicId]) AS [ItemPublicId], r.[Title], r.[Offered],
                   r.[Assigned], r.[Completed], r.[WaitingOnReview], r.[InProgress], r.[NotStarted], r.[Overdue],
                   COUNT(*) OVER () AS [Total]
            FROM [Rolled] r
            WHERE r.[Assigned] > 0 OR r.[Offered] = 1
            ORDER BY r.[ItemType], r.[Title], r.[PublicId]
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
            SQL,
            [
                ':now' => AzureSqlValueMapper::formatDateTime($nowUtc),
                ':today' => $today,
                ':offset' => $offset,
                ':pageSize' => $fetch,
            ],
        ];

        [$rows, $total] = $this->page($query, $page, $pageSize);

        $items = [];
        foreach ($rows as $row) {
            $type = AzureSqlValueMapper::stringValue($row['ItemType'] ?? '');
            $items[] = new ItemComplianceRow(
                self::itemType($type),
                strtolower(AzureSqlValueMapper::stringValue($row['ItemPublicId'] ?? '')),
                AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
                AzureSqlValueMapper::boolValue($row['Offered'] ?? false),
                AzureSqlValueMapper::intValue($row['Assigned'] ?? 0),
                AzureSqlValueMapper::intValue($row['Completed'] ?? 0),
                AzureSqlValueMapper::intValue($row['WaitingOnReview'] ?? 0),
                AzureSqlValueMapper::intValue($row['InProgress'] ?? 0),
                AzureSqlValueMapper::intValue($row['NotStarted'] ?? 0),
                AzureSqlValueMapper::intValue($row['Overdue'] ?? 0),
            );
        }

        return ['items' => $items, 'total' => $total];
    }

    public function overdueWork(DateTimeImmutable $nowUtc, string $today, int $page, int $pageSize): array
    {
        $overdue = self::overdueCondition('a');
        $joins = self::ITEM_JOINS;

        $query = static fn (int $offset, int $fetch): array => [
            <<<SQL
            SELECT
                CONVERT(NVARCHAR(36), t.[PublicId]) AS [PersonPublicId],
                NULLIF(LTRIM(RTRIM(u.[DisplayName])), N'') AS [DisplayName],
                a.[ItemType],
                COALESCE(CONVERT(NVARCHAR(36), tm.[PublicId]), CONVERT(NVARCHAR(36), p.[PublicId]), CONVERT(NVARCHAR(36), fd.[PublicId])) AS [ItemPublicId],
                COALESCE(tm.[Title], p.[Title], fd.[Title]) AS [Title],
                a.[Status],
                CONVERT(VARCHAR(10), a.[DueDate], 23) AS [DueDate],
                COUNT(*) OVER () AS [Total]
            FROM [onboarding].[Assignments] a
            JOIN [onboarding].[TraineeProfiles] t ON t.[UserId] = a.[UserId]
            JOIN [identity].[Users] u ON u.[UserId] = a.[UserId]
            {$joins}
            WHERE a.[WithdrawnAtUtc] IS NULL
              AND (t.[DepartedAtUtc] IS NULL OR t.[DepartedAtUtc] > CONVERT(DATETIME2(7), :now))
              AND {$overdue}
            ORDER BY a.[DueDate], [DisplayName], a.[PublicId]
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
            SQL,
            [
                ':now' => AzureSqlValueMapper::formatDateTime($nowUtc),
                ':today' => $today,
                ':offset' => $offset,
                ':pageSize' => $fetch,
            ],
        ];

        [$rows, $total] = $this->page($query, $page, $pageSize);

        $items = [];
        foreach ($rows as $row) {
            $dueDate = AzureSqlValueMapper::stringValue($row['DueDate'] ?? '');
            $items[] = new OverdueWorkRow(
                strtolower(AzureSqlValueMapper::stringValue($row['PersonPublicId'] ?? '')),
                AzureSqlValueMapper::nullableString($row['DisplayName'] ?? null),
                self::itemType(AzureSqlValueMapper::stringValue($row['ItemType'] ?? '')),
                strtolower(AzureSqlValueMapper::stringValue($row['ItemPublicId'] ?? '')),
                AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
                AssignmentStatus::fromStorage($row['Status'] ?? null),
                $dueDate,
                OverduePolicy::daysOverdue($dueDate, $today),
            );
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Runs a windowed query for one page. A page past the end has no row to
     * carry `COUNT(*) OVER ()`, so the total is then read from the first row.
     *
     * @param callable(int, int): array{string, array<string, int|string>} $query
     *
     * @return array{list<array<string, mixed>>, int}
     */
    private function page(callable $query, int $page, int $pageSize): array
    {
        [$sql, $params] = $query(($page - 1) * $pageSize, $pageSize);
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === [] && $page > 1) {
            [$sql, $params] = $query(0, 1);
            $first = $this->connection->fetchAll($sql, $params);

            return [[], AzureSqlValueMapper::intValue($first[0]['Total'] ?? 0)];
        }

        return [$rows, AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0)];
    }

    /**
     * {@see OverduePolicy::isOverdue()} in SQL, for an alias with `[Status]`
     * and `[DueDate]`. Binds `:today`.
     */
    private static function overdueCondition(string $alias): string
    {
        return sprintf(
            '(%1$s.[DueDate] IS NOT NULL AND %1$s.[DueDate] < CONVERT(DATE, :today) AND %1$s.[Status] IN (%2$s))',
            $alias,
            self::statusList(AssignmentStatus::toBeWorkedOnSet()),
        );
    }

    /**
     * @param list<AssignmentStatus> $statuses
     */
    private static function statusList(array $statuses): string
    {
        return implode(', ', array_map(static fn (AssignmentStatus $s): string => "N'" . $s->toString() . "'", $statuses));
    }

    /**
     * @return 'training'|'policy'|'form'
     */
    private static function itemType(string $value): string
    {
        return match ($value) {
            'policy' => 'policy',
            'form' => 'form',
            default => 'training',
        };
    }
}
