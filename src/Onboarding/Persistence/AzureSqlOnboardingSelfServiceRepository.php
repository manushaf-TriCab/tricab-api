<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use DateTimeImmutable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAssignmentView;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTraineeRecord;
use TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository;

/**
 * Reads one person's own onboarding from `[onboarding]`.
 *
 * Two properties are load-bearing, and both are in the SQL rather than in PHP:
 *
 *  - **`WHERE [UserId] = :userId` on every statement.** Ownership is a filter in
 *    the query, never a check after the fetch. A check after the fetch is one an
 *    early return, an exception path or a later refactor can skip, and the
 *    failure mode is handing somebody another person's induction.
 *  - **`[WithdrawnAtUtc] IS NULL`.** Withdrawal is evidence-preserving — the row
 *    stays with everything attempted against it — but it is off the person's
 *    plate, and `UX_onboarding_Assignments_Live*` is filtered the same way, so
 *    this predicate is also what lets the query use those indexes.
 *
 * Nothing here writes, so nothing here needs a `RowVersion`. When Phase 3's
 * write path lands it should follow the platform's existing optimistic
 * concurrency shape — `RowVersionToken`, `ProductRowVersionMapper::toSqlBinary()`
 * and `UPDATE ... OUTPUT INSERTED.* ... WHERE [RowVersion] = :expected`, with an
 * empty result meaning conflict — as `AzureSqlExpensePeriodRepository` does. It
 * is worth naming that here because the obvious alternative, `execute()` and a
 * row count, is not available: {@see SqlConnection::execute()} returns void.
 */
final class AzureSqlOnboardingSelfServiceRepository implements OnboardingSelfServiceRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findTraineeRecord(int $userId): ?OnboardingTraineeRecord
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId],
                CONVERT(VARCHAR(10), t.[StartDate], 23) AS [StartDate],
                CONVERT(VARCHAR(33), t.[DepartedAtUtc], 127) AS [DepartedAtUtc]
            FROM [onboarding].[TraineeProfiles] t
            WHERE t.[UserId] = :userId
            SQL,
            [':userId' => $userId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return new OnboardingTraineeRecord(
            self::asString($row['PublicId'] ?? null) ?? '',
            self::asString($row['StartDate'] ?? null),
            self::asUtc($row['DepartedAtUtc'] ?? null),
        );
    }

    public function listAssignments(int $userId): array
    {
        /**
         * One statement with three LEFT JOINs rather than three statements and a
         * merge in PHP. `CK_onboarding_Assignments_ExactlyOneItem` guarantees
         * exactly one of the three keys is set per row, so the COALESCEs are
         * total — there is no row for which all three are null, and none for
         * which two are set.
         *
         * `ORDER BY` is here as well as in `WorklistOrder` on purpose. The
         * domain service decides the order the person sees; this one only makes
         * the row set deterministic, so a failing test names one row rather than
         * a different row each run.
         */
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT
                CONVERT(NVARCHAR(36), a.[PublicId]) AS [PublicId],
                a.[ItemType],
                COALESCE(
                    CONVERT(NVARCHAR(36), tm.[PublicId]),
                    CONVERT(NVARCHAR(36), p.[PublicId]),
                    CONVERT(NVARCHAR(36), fd.[PublicId])
                ) AS [ItemPublicId],
                COALESCE(tm.[Title], p.[Title], fd.[Title]) AS [Title],
                COALESCE(tmv.[VersionNumber], pv.[VersionNumber], fdv.[VersionNumber]) AS [VersionNumber],
                a.[Status],
                CONVERT(VARCHAR(10), a.[DueDate], 23) AS [DueDate],
                CONVERT(VARCHAR(33), a.[AllocatedAtUtc], 127) AS [AllocatedAtUtc],
                CONVERT(VARCHAR(33), a.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
                CONVERT(VARCHAR(33), a.[CompletedAtUtc], 127) AS [CompletedAtUtc]
            FROM [onboarding].[Assignments] a
            LEFT JOIN [onboarding].[TrainingModuleVersions] tmv
                ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
            LEFT JOIN [onboarding].[TrainingModules] tm
                ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
            LEFT JOIN [onboarding].[PolicyVersions] pv
                ON pv.[PolicyVersionId] = a.[PolicyVersionId]
            LEFT JOIN [onboarding].[Policies] p
                ON p.[PolicyId] = pv.[PolicyId]
            LEFT JOIN [onboarding].[FormDefinitionVersions] fdv
                ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
            LEFT JOIN [onboarding].[FormDefinitions] fd
                ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
            WHERE a.[UserId] = :userId
              AND a.[WithdrawnAtUtc] IS NULL
            ORDER BY a.[AllocatedAtUtc], a.[AssignmentId]
            SQL,
            [':userId' => $userId],
        );

        $assignments = [];
        foreach ($rows as $row) {
            $assignments[] = OnboardingAssignmentView::fromStorage(
                self::asString($row['PublicId'] ?? null) ?? '',
                self::asString($row['ItemType'] ?? null) ?? '',
                self::asString($row['ItemPublicId'] ?? null) ?? '',
                self::asString($row['Title'] ?? null) ?? '',
                (int) (is_numeric($row['VersionNumber'] ?? null) ? $row['VersionNumber'] : 0),
                $row['Status'] ?? null,
                self::asString($row['DueDate'] ?? null),
                self::asString($row['AllocatedAtUtc'] ?? null) ?? '',
                self::asString($row['SubmittedAtUtc'] ?? null),
                self::asString($row['CompletedAtUtc'] ?? null),
            );
        }

        return $assignments;
    }

    private static function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    /**
     * Style 127 gives an ISO-8601 string; anything else is a driver returning
     * something unexpected, and a null departure is safer than a guessed one —
     * it leaves the person their access rather than revoking it on a parse
     * failure they cannot see.
     */
    private static function asUtc(mixed $value): ?DateTimeImmutable
    {
        $text = self::asString($value);

        if ($text === null || $text === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, rtrim($text, 'Z') . '+00:00')
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', rtrim($text, 'Z'));

        return $parsed === false ? null : $parsed;
    }
}
