<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\OnboardingLink;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAdminAuditLog;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;

/**
 * The onboarding-to-employee link on Azure SQL. Reads the base employee columns
 * of `[hr].[Employees]` and never `[hr].[EmployeeRestrictedDetails]`.
 */
final class AzureSqlOnboardingLinkRepository implements OnboardingLinkRepository
{
    use AzureSqlTransaction;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function available(): bool
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT CASE WHEN OBJECT_ID(N'[hr].[OnboardingLinkProposals]', N'U') IS NOT NULL
                         AND COL_LENGTH(N'[onboarding].[TraineeProfiles]', N'EmployeeCode') IS NOT NULL
                        THEN 1 ELSE 0 END AS [Ok]
            SQL,
        );

        return AzureSqlValueMapper::intValue($rows[0]['Ok'] ?? 0) === 1;
    }

    public function unlinkedStarters(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT t.[TraineeProfileId], t.[UserId], u.[DisplayName],
                   CONVERT(VARCHAR(10), t.[StartDate], 23) AS [StartDate]
            FROM [onboarding].[TraineeProfiles] t
            JOIN [identity].[Users] u ON u.[UserId] = t.[UserId]
            WHERE t.[EmployeeCode] IS NULL
            SQL,
        );

        $starters = [];
        foreach ($rows as $row) {
            $starters[] = [
                'profileId' => AzureSqlValueMapper::intValue($row['TraineeProfileId'] ?? 0),
                'userId' => AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
                'displayName' => AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
                'startDate' => AzureSqlValueMapper::nullableString($row['StartDate'] ?? null),
            ];
        }

        return $starters;
    }

    public function linkableEmployees(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT e.[EmployeeCode], e.[UserId], e.[DisplayName], e.[FirstName], e.[LastName],
                   CONVERT(VARCHAR(10), e.[DateHired], 23) AS [DateHired]
            FROM [hr].[Employees] e
            WHERE e.[IsCurrent] = 1
              AND NOT EXISTS (SELECT 1 FROM [onboarding].[TraineeProfiles] t WHERE t.[EmployeeCode] = e.[EmployeeCode])
            SQL,
        );

        $employees = [];
        foreach ($rows as $row) {
            $employees[] = [
                'code' => AzureSqlValueMapper::stringValue($row['EmployeeCode'] ?? ''),
                'userId' => AzureSqlValueMapper::nullableInt($row['UserId'] ?? null),
                'displayName' => AzureSqlValueMapper::nullableString($row['DisplayName'] ?? null),
                'firstName' => AzureSqlValueMapper::nullableString($row['FirstName'] ?? null),
                'lastName' => AzureSqlValueMapper::nullableString($row['LastName'] ?? null),
                'dateHired' => AzureSqlValueMapper::nullableString($row['DateHired'] ?? null),
            ];
        }

        return $employees;
    }

    public function proposedPairs(): array
    {
        $pairs = [];
        foreach ($this->connection->fetchAll('SELECT [TraineeProfileId], [EmployeeCode] FROM [hr].[OnboardingLinkProposals]') as $row) {
            $pairs[AzureSqlValueMapper::intValue($row['TraineeProfileId'] ?? 0) . '|' . AzureSqlValueMapper::stringValue($row['EmployeeCode'] ?? '')] = true;
        }

        return $pairs;
    }

    public function addProposals(array $proposals): int
    {
        return $this->runInTransaction($this->connection, function () use ($proposals): int {
            $stored = 0;
            foreach ($proposals as $proposal) {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
                    SET NOCOUNT ON;
                    INSERT INTO [hr].[OnboardingLinkProposals] ([TraineeProfileId], [EmployeeCode], [MatchReason])
                    SELECT :profileId, :employeeCode, :reason
                    WHERE NOT EXISTS (
                        SELECT 1 FROM [hr].[OnboardingLinkProposals] x
                        WHERE x.[TraineeProfileId] = :profileId2 AND x.[EmployeeCode] = :employeeCode2
                    );
                    SELECT @@ROWCOUNT AS [Affected];
                    SQL,
                    [
                        ':profileId' => $proposal['profileId'],
                        ':employeeCode' => $proposal['employeeCode'],
                        ':reason' => $proposal['reason'],
                        ':profileId2' => $proposal['profileId'],
                        ':employeeCode2' => $proposal['employeeCode'],
                    ],
                );
                $stored += AzureSqlValueMapper::intValue($rows[0]['Affected'] ?? 0);
            }

            return $stored;
        });
    }

    public function profile(string $personPublicId): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT [TraineeProfileId], [UserId], [EmployeeCode],
                   CONVERT(VARCHAR(33), [EmployeeLinkedAtUtc], 127) AS [LinkedAtUtc]
            FROM [onboarding].[TraineeProfiles]
            WHERE [PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :publicId)
            SQL,
            [':publicId' => $personPublicId],
        );
        if ($rows === []) {
            return null;
        }

        return [
            'profileId' => AzureSqlValueMapper::intValue($rows[0]['TraineeProfileId'] ?? 0),
            'userId' => AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? 0),
            'employeeCode' => AzureSqlValueMapper::nullableString($rows[0]['EmployeeCode'] ?? null),
            'linkedAtUtc' => AzureSqlValueMapper::nullableString($rows[0]['LinkedAtUtc'] ?? null),
        ];
    }

    public function personPublicIdForEmployee(string $employeeCode): ?string
    {
        $rows = $this->connection->fetchAll(
            'SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId] FROM [onboarding].[TraineeProfiles] WHERE [EmployeeCode] = :code',
            [':code' => $employeeCode],
        );

        return $rows === [] ? null : strtolower(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
    }

    public function pendingProposals(int $profileId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId], p.[EmployeeCode], p.[MatchReason],
                   CONVERT(VARCHAR(33), p.[ProposedAtUtc], 127) AS [ProposedAtUtc]
            FROM [hr].[OnboardingLinkProposals] p
            WHERE p.[TraineeProfileId] = :profileId
              AND p.[Status] = N'pending'
              AND NOT EXISTS (SELECT 1 FROM [onboarding].[TraineeProfiles] t WHERE t.[EmployeeCode] = p.[EmployeeCode])
            ORDER BY CASE p.[MatchReason] WHEN N'login' THEN 0 ELSE 1 END, p.[ProposedAtUtc], p.[ProposalId]
            SQL,
            [':profileId' => $profileId],
        );

        $proposals = [];
        foreach ($rows as $row) {
            $proposals[] = [
                'publicId' => strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
                'employeeCode' => AzureSqlValueMapper::stringValue($row['EmployeeCode'] ?? ''),
                'reason' => AzureSqlValueMapper::stringValue($row['MatchReason'] ?? ''),
                'proposedAtUtc' => AzureSqlValueMapper::stringValue($row['ProposedAtUtc'] ?? ''),
            ];
        }

        return $proposals;
    }

    public function proposal(int $profileId, string $proposalPublicId): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [EmployeeCode], [Status]
            FROM [hr].[OnboardingLinkProposals]
            WHERE [TraineeProfileId] = :profileId AND [PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :publicId)
            SQL,
            [':profileId' => $profileId, ':publicId' => $proposalPublicId],
        );
        if ($rows === []) {
            return null;
        }

        return [
            'publicId' => strtolower(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '')),
            'employeeCode' => AzureSqlValueMapper::stringValue($rows[0]['EmployeeCode'] ?? ''),
            'status' => AzureSqlValueMapper::stringValue($rows[0]['Status'] ?? ''),
        ];
    }

    public function confirm(int $profileId, string $proposalPublicId, string $employeeCode, ActorContext $actor, OnboardingAdminEvent $event): void
    {
        $this->runInTransaction($this->connection, function () use ($profileId, $proposalPublicId, $employeeCode, $actor, $event): void {
            // Claim the proposal first: a second HR user's confirm of the same one stops here.
            if ($this->decide($profileId, $proposalPublicId, 'confirmed', $actor) === 0) {
                throw OnboardingLinkRefused::proposalDecided();
            }

            // The employee must not belong to anyone else; the range lock holds the answer until commit.
            $taken = $this->connection->fetchAll(
                'SELECT 1 AS [Taken] FROM [onboarding].[TraineeProfiles] WITH (UPDLOCK, HOLDLOCK) WHERE [EmployeeCode] = :code',
                [':code' => $employeeCode],
            );
            if ($taken !== []) {
                throw OnboardingLinkRefused::employeeAlreadyLinked();
            }

            $linked = $this->connection->fetchAll(
                <<<'SQL'
                SET NOCOUNT ON;
                UPDATE [onboarding].[TraineeProfiles]
                SET [EmployeeCode] = :code, [EmployeeLinkedAtUtc] = SYSUTCDATETIME(),
                    [EmployeeLinkedByUserId] = :actorUserId, [UpdatedAtUtc] = SYSUTCDATETIME()
                WHERE [TraineeProfileId] = :profileId AND [EmployeeCode] IS NULL;
                SELECT @@ROWCOUNT AS [Affected];
                SQL,
                [':code' => $employeeCode, ':actorUserId' => $actor->userId, ':profileId' => $profileId],
            );
            if (AzureSqlValueMapper::intValue($linked[0]['Affected'] ?? 0) === 0) {
                throw OnboardingLinkRefused::personAlreadyLinked();
            }

            // Everything else still hanging about this person or this employee is moot.
            $this->connection->execute(
                <<<'SQL'
                UPDATE [hr].[OnboardingLinkProposals]
                SET [Status] = N'superseded', [DecidedAtUtc] = SYSUTCDATETIME(), [DecidedByUserId] = :actorUserId
                WHERE [Status] = N'pending'
                  AND ([TraineeProfileId] = :profileId OR [EmployeeCode] = :code)
                SQL,
                [':actorUserId' => $actor->userId, ':profileId' => $profileId, ':code' => $employeeCode],
            );

            AzureSqlOnboardingAdminAuditLog::appendWithin($this->connection, $actor, $event);
        });
    }

    public function dismiss(int $profileId, string $proposalPublicId, ActorContext $actor, OnboardingAdminEvent $event): void
    {
        $this->runInTransaction($this->connection, function () use ($profileId, $proposalPublicId, $actor, $event): void {
            if ($this->decide($profileId, $proposalPublicId, 'dismissed', $actor) === 0) {
                throw OnboardingLinkRefused::proposalDecided();
            }
            AzureSqlOnboardingAdminAuditLog::appendWithin($this->connection, $actor, $event);
        });
    }

    public function unlink(int $profileId, ActorContext $actor, OnboardingAdminEvent $event): void
    {
        $this->runInTransaction($this->connection, function () use ($profileId, $actor, $event): void {
            $cleared = $this->connection->fetchAll(
                <<<'SQL'
                UPDATE [onboarding].[TraineeProfiles]
                SET [EmployeeCode] = NULL, [EmployeeLinkedAtUtc] = NULL, [EmployeeLinkedByUserId] = NULL,
                    [UpdatedAtUtc] = SYSUTCDATETIME()
                OUTPUT DELETED.[EmployeeCode] AS [EmployeeCode]
                WHERE [TraineeProfileId] = :profileId AND [EmployeeCode] IS NOT NULL
                SQL,
                [':profileId' => $profileId],
            );
            if ($cleared === []) {
                throw OnboardingLinkRefused::notLinked();
            }

            // The pair stays on record, dismissed, so the next sync does not offer it again.
            $this->connection->execute(
                <<<'SQL'
                UPDATE [hr].[OnboardingLinkProposals]
                SET [Status] = N'dismissed', [DecidedAtUtc] = SYSUTCDATETIME(), [DecidedByUserId] = :actorUserId
                WHERE [TraineeProfileId] = :profileId AND [EmployeeCode] = :code AND [Status] = N'confirmed'
                SQL,
                [
                    ':actorUserId' => $actor->userId,
                    ':profileId' => $profileId,
                    ':code' => AzureSqlValueMapper::stringValue($cleared[0]['EmployeeCode'] ?? ''),
                ],
            );

            AzureSqlOnboardingAdminAuditLog::appendWithin($this->connection, $actor, $event);
        });
    }

    /** @return int rows changed: 0 when the proposal is not pending (or not this person's) */
    private function decide(int $profileId, string $proposalPublicId, string $status, ActorContext $actor): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SET NOCOUNT ON;
            UPDATE [hr].[OnboardingLinkProposals]
            SET [Status] = :status, [DecidedAtUtc] = SYSUTCDATETIME(), [DecidedByUserId] = :actorUserId
            WHERE [TraineeProfileId] = :profileId
              AND [PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :publicId)
              AND [Status] = N'pending';
            SELECT @@ROWCOUNT AS [Affected];
            SQL,
            [':status' => $status, ':actorUserId' => $actor->userId, ':profileId' => $profileId, ':publicId' => $proposalPublicId],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Affected'] ?? 0);
    }
}
