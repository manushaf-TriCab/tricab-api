<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use PDOException;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocatableItem;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationPlan;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * HR's roster and the allocation write, against `tricab-mel`.
 *
 * Reads `[onboarding]` and `[identity].[Users]` only. Nothing here selects
 * from `[onboarding_hr]` — the roster is a list view and invariant 11 keeps
 * restricted data out of list views — and `OnboardingPeopleRepositoryBoundaryTest`
 * reads this file to hold that line.
 */
final class AzureSqlOnboardingPeopleRepository implements OnboardingPeopleRepository
{
    use AzureSqlTransaction;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** The version column each item type's assignment points at. */
    private const VERSION_COLUMN = [
        'training' => 'TrainingModuleVersionId',
        'policy' => 'PolicyVersionId',
        'form' => 'FormDefinitionVersionId',
    ];

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listPeople(int $page, int $pageSize, ?string $search): array
    {
        $params = [':offset' => ($page - 1) * $pageSize, ':pageSize' => $pageSize];
        $where = '';
        if ($search !== null) {
            // LIKE wildcards in the term are literal: somebody searching for
            // "o_brien" means the underscore.
            $pattern = '%' . strtr($search, ['[' => '[[]', '%' => '[%]', '_' => '[_]']) . '%';
            $where = 'WHERE u.[DisplayName] LIKE :searchName OR u.[EmailAddress] LIKE :searchEmail';
            $params[':searchName'] = $pattern;
            $params[':searchEmail'] = $pattern;
        }

        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT
                t.[UserId],
                CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId],
                COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), u.[EmailAddress]) AS [DisplayName],
                u.[EmailAddress],
                t.[Position], t.[Department], t.[Branch],
                CONVERT(VARCHAR(10), t.[StartDate], 23) AS [StartDate],
                CONVERT(VARCHAR(33), t.[DepartedAtUtc], 127) AS [DepartedAtUtc],
                CONVERT(VARCHAR(33), t.[InvitedAtUtc], 127) AS [InvitedAtUtc],
                COUNT(*) OVER () AS [Total]
            FROM [onboarding].[TraineeProfiles] t
            JOIN [identity].[Users] u ON u.[UserId] = t.[UserId]
            {$where}
            ORDER BY COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), u.[EmailAddress]), t.[PublicId]
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
            SQL,
            $params,
        );

        $counts = $this->statusCounts(array_map(
            static fn (array $row): int => AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            $rows,
        ));

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::summary($row, $counts[AzureSqlValueMapper::intValue($row['UserId'] ?? 0)] ?? []);
        }

        return [
            'items' => $items,
            'total' => $rows === [] ? $this->countPeople($search) : AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0),
        ];
    }

    public function findPerson(string $personPublicId): ?OnboardingPersonRecord
    {
        $row = $this->personRow($personPublicId);
        if ($row === null) {
            return null;
        }

        return new OnboardingPersonRecord(
            AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
            AzureSqlValueMapper::nullableDateTime($row['DepartedAtUtc'] ?? null),
        );
    }

    public function personDetail(string $personPublicId): ?OnboardingPersonDetail
    {
        $row = $this->personRow($personPublicId);
        if ($row === null) {
            return null;
        }

        $userId = AzureSqlValueMapper::intValue($row['UserId'] ?? 0);
        $allocations = $this->listCurrentAllocations($userId);
        $counts = [];
        foreach ($allocations as $allocation) {
            $code = $allocation->status->toString();
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return new OnboardingPersonDetail(
            self::summary($row, $counts),
            ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            $allocations,
            $this->listAllocatableItems(),
        );
    }

    public function listAllocatableItems(): array
    {
        // One published, live version per active item — the filtered unique
        // indexes UX_..._OneLive guarantee "one", this guarantees "live".
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT N'training' AS [ItemType], CONVERT(NVARCHAR(36), m.[PublicId]) AS [ItemPublicId], m.[Title],
                   v.[TrainingModuleVersionId] AS [VersionId], CONVERT(NVARCHAR(36), v.[PublicId]) AS [VersionPublicId],
                   v.[VersionNumber]
            FROM [onboarding].[TrainingModules] m
            JOIN [onboarding].[TrainingModuleVersions] v ON v.[TrainingModuleId] = m.[TrainingModuleId]
            WHERE m.[IsActive] = 1 AND v.[IsPublished] = 1 AND v.[RetiredAtUtc] IS NULL
            UNION ALL
            SELECT N'policy', CONVERT(NVARCHAR(36), p.[PublicId]), p.[Title],
                   v.[PolicyVersionId], CONVERT(NVARCHAR(36), v.[PublicId]), v.[VersionNumber]
            FROM [onboarding].[Policies] p
            JOIN [onboarding].[PolicyVersions] v ON v.[PolicyId] = p.[PolicyId]
            WHERE p.[IsActive] = 1 AND v.[IsPublished] = 1 AND v.[SupersededAtUtc] IS NULL
            UNION ALL
            SELECT N'form', CONVERT(NVARCHAR(36), f.[PublicId]), f.[Title],
                   v.[FormDefinitionVersionId], CONVERT(NVARCHAR(36), v.[PublicId]), v.[VersionNumber]
            FROM [onboarding].[FormDefinitions] f
            JOIN [onboarding].[FormDefinitionVersions] v ON v.[FormDefinitionId] = f.[FormDefinitionId]
            WHERE f.[IsActive] = 1 AND v.[IsPublished] = 1 AND v.[RetiredAtUtc] IS NULL
            ORDER BY [ItemType], [Title], [ItemPublicId]
            SQL,
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = new AllocatableItem(
                AzureSqlValueMapper::stringValue($row['ItemType'] ?? ''),
                strtolower(AzureSqlValueMapper::stringValue($row['ItemPublicId'] ?? '')),
                AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
                AzureSqlValueMapper::intValue($row['VersionId'] ?? 0),
                strtolower(AzureSqlValueMapper::stringValue($row['VersionPublicId'] ?? '')),
                AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
            );
        }

        return $items;
    }

    public function listCurrentAllocations(int $userId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT
                a.[AssignmentId],
                CONVERT(NVARCHAR(36), a.[PublicId]) AS [PublicId],
                a.[ItemType],
                COALESCE(CONVERT(NVARCHAR(36), tm.[PublicId]), CONVERT(NVARCHAR(36), p.[PublicId]), CONVERT(NVARCHAR(36), fd.[PublicId])) AS [ItemPublicId],
                COALESCE(tm.[Title], p.[Title], fd.[Title]) AS [Title],
                COALESCE(tmv.[VersionNumber], pv.[VersionNumber], fdv.[VersionNumber]) AS [VersionNumber],
                a.[Status],
                CONVERT(VARCHAR(10), a.[DueDate], 23) AS [DueDate],
                CONVERT(VARCHAR(33), a.[AllocatedAtUtc], 127) AS [AllocatedAtUtc],
                CASE WHEN EXISTS (SELECT 1 FROM [onboarding].[PresentationProgress] e WHERE e.[AssignmentId] = a.[AssignmentId])
                       OR EXISTS (SELECT 1 FROM [onboarding].[Attempts] e WHERE e.[AssignmentId] = a.[AssignmentId])
                       OR EXISTS (SELECT 1 FROM [onboarding].[PolicyAcknowledgements] e WHERE e.[AssignmentId] = a.[AssignmentId])
                       OR EXISTS (SELECT 1 FROM [onboarding].[FormDrafts] e WHERE e.[AssignmentId] = a.[AssignmentId])
                       OR EXISTS (SELECT 1 FROM [onboarding].[FormSubmissions] e WHERE e.[AssignmentId] = a.[AssignmentId])
                     THEN 1 ELSE 0 END AS [HasEvidence]
            FROM [onboarding].[Assignments] a
            LEFT JOIN [onboarding].[TrainingModuleVersions] tmv ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
            LEFT JOIN [onboarding].[TrainingModules] tm ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
            LEFT JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
            LEFT JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
            LEFT JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
            LEFT JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
            WHERE a.[UserId] = :userId
              AND a.[WithdrawnAtUtc] IS NULL
            ORDER BY a.[AllocatedAtUtc], a.[AssignmentId]
            SQL,
            [':userId' => $userId],
        );

        $allocations = [];
        foreach ($rows as $row) {
            $allocations[] = new CurrentAllocation(
                AzureSqlValueMapper::intValue($row['AssignmentId'] ?? 0),
                strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
                AzureSqlValueMapper::stringValue($row['ItemType'] ?? ''),
                strtolower(AzureSqlValueMapper::stringValue($row['ItemPublicId'] ?? '')),
                AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
                AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
                AssignmentStatus::fromStorage($row['Status'] ?? null),
                AzureSqlValueMapper::nullableString($row['DueDate'] ?? null),
                AzureSqlValueMapper::boolValue($row['HasEvidence'] ?? false),
                AzureSqlValueMapper::stringValue($row['AllocatedAtUtc'] ?? ''),
            );
        }

        return $allocations;
    }

    public function applyAllocation(
        OnboardingPersonRecord $person,
        RowVersionToken $expectedRowVersion,
        AllocationPlan $plan,
        array $newAssignmentPublicIds,
        array $events,
        ActorContext $actor,
    ): void {
        $this->runInTransaction($this->connection, function () use (
            $person,
            $expectedRowVersion,
            $plan,
            $newAssignmentPublicIds,
            $events,
            $actor,
        ): void {
            // The token first: a second HR user's stale save fails before it
            // has written anything.
            $claimed = $this->connection->fetchAll(
                <<<'SQL'
                UPDATE [onboarding].[TraineeProfiles]
                SET [UpdatedAtUtc] = SYSUTCDATETIME()
                OUTPUT INSERTED.[TraineeProfileId]
                WHERE [UserId] = :userId
                  AND [RowVersion] = :expectedRowVersion
                SQL,
                [
                    ':userId' => $person->userId,
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expectedRowVersion),
                ],
            );
            if ($claimed === []) {
                throw OnboardingWriteConflict::staleToken();
            }

            foreach ($plan->allocate as $entry) {
                $this->insertAssignment($person->userId, $entry['item'], $entry['dueDate'], $newAssignmentPublicIds[$entry['item']->key()], $actor->userId);
            }

            foreach ($plan->withdraw as $allocation) {
                $this->withdraw($allocation, $actor->userId);
            }

            foreach ($plan->dueDateChanges as $change) {
                $this->changeDueDate($change['allocation'], $change['toDueDate']);
            }

            foreach ($events as $event) {
                AzureSqlOnboardingAdminAuditLog::appendWithin($this->connection, $actor, $event);
            }
        });
    }

    // ------------------------------------------------------------------ writes

    private function insertAssignment(int $userId, AllocatableItem $item, ?string $dueDate, string $publicId, int $actorUserId): void
    {
        $column = self::VERSION_COLUMN[$item->itemType];

        try {
            $this->connection->execute(
                <<<SQL
                INSERT INTO [onboarding].[Assignments] (
                    [PublicId], [UserId], [ItemType], [{$column}], [AllocatedByUserId], [DueDate]
                ) VALUES (
                    CONVERT(UNIQUEIDENTIFIER, :publicId), :userId, :itemType, :versionId, :allocatedBy,
                    TRY_CONVERT(DATE, :dueDate, 23)
                )
                SQL,
                [
                    ':publicId' => $publicId,
                    ':userId' => $userId,
                    ':itemType' => $item->itemType,
                    ':versionId' => $item->versionId,
                    ':allocatedBy' => $actorUserId,
                    ':dueDate' => $dueDate,
                ],
            );
        } catch (PDOException $failure) {
            // UX_onboarding_Assignments_Live*: somebody allocated the same item
            // between the plan and this insert. The token makes that another HR
            // save racing this one, and the answer is the same as for a stale
            // token.
            if (self::isUniqueViolation($failure)) {
                throw OnboardingWriteConflict::staleToken();
            }
            throw $failure;
        }
    }

    private function withdraw(CurrentAllocation $allocation, int $actorUserId): void
    {
        $withdrawn = $this->connection->fetchAll(
            <<<'SQL'
            UPDATE a
            SET [WithdrawnAtUtc] = SYSUTCDATETIME(),
                [WithdrawnByUserId] = :actorUserId,
                [WithdrawalReason] = N'Unticked by HR before any work was recorded.',
                [UpdatedAtUtc] = SYSUTCDATETIME()
            OUTPUT INSERTED.[AssignmentId]
            FROM [onboarding].[Assignments] a
            WHERE a.[AssignmentId] = :assignmentId
              AND a.[WithdrawnAtUtc] IS NULL
              AND a.[Status] = N'not_started'
              AND NOT EXISTS (SELECT 1 FROM [onboarding].[PresentationProgress] e WHERE e.[AssignmentId] = a.[AssignmentId])
              AND NOT EXISTS (SELECT 1 FROM [onboarding].[Attempts] e WHERE e.[AssignmentId] = a.[AssignmentId])
              AND NOT EXISTS (SELECT 1 FROM [onboarding].[PolicyAcknowledgements] e WHERE e.[AssignmentId] = a.[AssignmentId])
              AND NOT EXISTS (SELECT 1 FROM [onboarding].[FormDrafts] e WHERE e.[AssignmentId] = a.[AssignmentId])
              AND NOT EXISTS (SELECT 1 FROM [onboarding].[FormSubmissions] e WHERE e.[AssignmentId] = a.[AssignmentId])
            SQL,
            [':actorUserId' => $actorUserId, ':assignmentId' => $allocation->assignmentId],
        );

        if ($withdrawn === []) {
            throw OnboardingWriteConflict::staleToken();
        }
    }

    private function changeDueDate(CurrentAllocation $allocation, ?string $toDueDate): void
    {
        $changed = $this->connection->fetchAll(
            <<<'SQL'
            UPDATE [onboarding].[Assignments]
            SET [DueDate] = TRY_CONVERT(DATE, :dueDate, 23),
                [UpdatedAtUtc] = SYSUTCDATETIME()
            OUTPUT INSERTED.[AssignmentId]
            WHERE [AssignmentId] = :assignmentId
              AND [WithdrawnAtUtc] IS NULL
              AND [Status] <> N'completed'
            SQL,
            [':dueDate' => $toDueDate, ':assignmentId' => $allocation->assignmentId],
        );

        if ($changed === []) {
            throw OnboardingWriteConflict::staleToken();
        }
    }

    // ------------------------------------------------------------------- reads

    /**
     * @return array<string, mixed>|null
     */
    private function personRow(string $personPublicId): ?array
    {
        // Checked before CONVERT: a malformed id is "no such person", not a
        // conversion error surfacing as a 500.
        if (preg_match(self::UUID, $personPublicId) !== 1) {
            return null;
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                t.[UserId],
                CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId],
                CONVERT(VARBINARY(8), t.[RowVersion]) AS [RowVersion],
                COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), u.[EmailAddress]) AS [DisplayName],
                u.[EmailAddress],
                t.[Position], t.[Department], t.[Branch],
                CONVERT(VARCHAR(10), t.[StartDate], 23) AS [StartDate],
                CONVERT(VARCHAR(33), t.[DepartedAtUtc], 127) AS [DepartedAtUtc],
                CONVERT(VARCHAR(33), t.[InvitedAtUtc], 127) AS [InvitedAtUtc]
            FROM [onboarding].[TraineeProfiles] t
            JOIN [identity].[Users] u ON u.[UserId] = t.[UserId]
            WHERE t.[PublicId] = CONVERT(UNIQUEIDENTIFIER, :publicId)
            SQL,
            [':publicId' => $personPublicId],
        );

        return $rows[0] ?? null;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, array<string, int>>
     */
    private function statusCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $params = [];
        $names = [];
        foreach (array_values(array_unique($userIds)) as $index => $userId) {
            $names[] = ':u' . $index;
            $params[':u' . $index] = $userId;
        }
        $in = implode(', ', $names);

        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT [UserId], [Status], COUNT(*) AS [Count]
            FROM [onboarding].[Assignments]
            WHERE [WithdrawnAtUtc] IS NULL AND [UserId] IN ({$in})
            GROUP BY [UserId], [Status]
            SQL,
            $params,
        );

        $counts = [];
        foreach ($rows as $row) {
            $userId = AzureSqlValueMapper::intValue($row['UserId'] ?? 0);
            $status = AssignmentStatus::fromStorage($row['Status'] ?? null)->toString();
            $counts[$userId][$status] = AzureSqlValueMapper::intValue($row['Count'] ?? 0);
        }

        return $counts;
    }

    private function countPeople(?string $search): int
    {
        if ($search === null) {
            $rows = $this->connection->fetchAll('SELECT COUNT(*) AS [Total] FROM [onboarding].[TraineeProfiles]');
        } else {
            $pattern = '%' . strtr($search, ['[' => '[[]', '%' => '[%]', '_' => '[_]']) . '%';
            $rows = $this->connection->fetchAll(
                <<<'SQL'
                SELECT COUNT(*) AS [Total]
                FROM [onboarding].[TraineeProfiles] t
                JOIN [identity].[Users] u ON u.[UserId] = t.[UserId]
                WHERE u.[DisplayName] LIKE :searchName OR u.[EmailAddress] LIKE :searchEmail
                SQL,
                [':searchName' => $pattern, ':searchEmail' => $pattern],
            );
        }

        return AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $counts
     */
    private static function summary(array $row, array $counts): OnboardingPersonSummary
    {
        return new OnboardingPersonSummary(
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
            AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? ''),
            AzureSqlValueMapper::nullableString($row['Position'] ?? null),
            AzureSqlValueMapper::nullableString($row['Department'] ?? null),
            AzureSqlValueMapper::nullableString($row['Branch'] ?? null),
            AzureSqlValueMapper::nullableString($row['StartDate'] ?? null),
            AzureSqlValueMapper::nullableString($row['DepartedAtUtc'] ?? null),
            AzureSqlValueMapper::nullableString($row['InvitedAtUtc'] ?? null),
            $counts,
        );
    }

    private static function isUniqueViolation(PDOException $failure): bool
    {
        $info = $failure->errorInfo;
        $native = is_array($info) ? ($info[1] ?? null) : null;

        return $native === 2601 || $native === 2627
            || str_contains($failure->getMessage(), 'duplicate key');
    }
}
