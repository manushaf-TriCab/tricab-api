<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Contract\OnboardingReviewRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter;
use TriCab\Core\Onboarding\Domain\Service\ReviewFieldProjection;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * The reviewer queue and the review decision, against `tricab-mel` (4.3).
 *
 * ## What it reads from `[onboarding_hr]`, and what it never does
 *
 * One table: `FieldClassifications`, which is metadata — which field is which
 * class — and is what lets {@see ReviewFieldProjection} withhold a payroll
 * field by name. Nothing here selects from `RestrictedSubmissionData`,
 * `PayrollDetails`, `RestrictedPersonalDetails` or `EmergencyContacts`; a
 * reviewer who needs those reads them through the audited document route.
 * `OnboardingReviewRepositoryBoundaryTest` reads this file to hold that line.
 *
 * ## The queue's order is the domain's
 *
 * `ORDER BY` is generated from {@see AssignmentStatus::queueOrder()} rather than
 * written out, so the priority stays in one place — the source's reason for
 * sorting in application code, kept without giving up SQL pagination.
 */
final class AzureSqlOnboardingReviewRepository implements OnboardingReviewRepository
{
    use AzureSqlTransaction;
    use AzureSqlOnboardingStatusWriter;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Bounded, like every list: an item reworked forty times shows its latest forty moves plus some. */
    private const HISTORY_LIMIT = 100;

    /**
     * The item columns every query here shares. Joins each version table to its
     * item, as the roster's `listCurrentAllocations()` does.
     */
    private const ITEM_JOINS = <<<'SQL'
        JOIN [identity].[Users] u ON u.[UserId] = a.[UserId]
        LEFT JOIN [onboarding].[TraineeProfiles] t ON t.[UserId] = a.[UserId]
        LEFT JOIN [onboarding].[TrainingModuleVersions] tmv ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
        LEFT JOIN [onboarding].[TrainingModules] tm ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
        LEFT JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
        LEFT JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
        LEFT JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
        LEFT JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
        SQL;

    private const ITEM_COLUMNS = <<<'SQL'
        a.[AssignmentId],
        CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssignmentPublicId],
        a.[UserId],
        CONVERT(NVARCHAR(36), t.[PublicId]) AS [PersonPublicId],
        COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), u.[EmailAddress]) AS [DisplayName],
        a.[ItemType],
        COALESCE(CONVERT(NVARCHAR(36), tm.[PublicId]), CONVERT(NVARCHAR(36), p.[PublicId]), CONVERT(NVARCHAR(36), fd.[PublicId])) AS [ItemPublicId],
        COALESCE(tm.[Title], p.[Title], fd.[Title]) AS [Title],
        COALESCE(tmv.[VersionNumber], pv.[VersionNumber], fdv.[VersionNumber]) AS [VersionNumber],
        a.[Status],
        CONVERT(VARCHAR(10), a.[DueDate], 23) AS [DueDate],
        (SELECT MAX(fs.[RevisionNumber]) FROM [onboarding].[FormSubmissions] fs WHERE fs.[AssignmentId] = a.[AssignmentId]) AS [RevisionNumber]
        SQL;

    /**
     * When the item joined the queue. A submission's own timestamp where there
     * is one; otherwise the row's last change, which for `need_help` is the
     * trainee raising it.
     */
    private const WAITING_SINCE = "CASE WHEN a.[Status] = N'submitted' THEN COALESCE(a.[SubmittedAtUtc], a.[UpdatedAtUtc]) ELSE a.[UpdatedAtUtc] END";

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listQueue(int $page, int $pageSize): array
    {
        $queued = self::queuedStatusList();
        $order = self::queueOrderCase();
        $waiting = self::WAITING_SINCE;
        $columns = self::ITEM_COLUMNS;
        $joins = self::ITEM_JOINS;

        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT
                {$columns},
                CONVERT(VARCHAR(33), {$waiting}, 127) AS [WaitingSinceUtc],
                COUNT(*) OVER () AS [Total]
            FROM [onboarding].[Assignments] a
            {$joins}
            WHERE a.[WithdrawnAtUtc] IS NULL
              AND a.[Status] IN ({$queued})
            ORDER BY {$order}, {$waiting}, a.[PublicId]
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
            SQL,
            [':offset' => ($page - 1) * $pageSize, ':pageSize' => $pageSize],
        );

        return [
            'items' => array_map(self::entry(...), $rows),
            'total' => $rows === [] ? $this->countQueue() : AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0),
        ];
    }

    public function findReviewItem(string $assignmentPublicId): ?ReviewItemRecord
    {
        $row = $this->itemRow($assignmentPublicId);
        if ($row === null) {
            return null;
        }

        return new ReviewItemRecord(
            AzureSqlValueMapper::intValue($row['AssignmentId'] ?? 0),
            strtolower(AzureSqlValueMapper::stringValue($row['AssignmentPublicId'] ?? '')),
            AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            AzureSqlValueMapper::stringValue($row['ItemType'] ?? ''),
            strtolower(AzureSqlValueMapper::stringValue($row['ItemPublicId'] ?? '')),
            AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            AssignmentStatus::fromStorage($row['Status'] ?? null),
            ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
            AzureSqlValueMapper::nullableInt($row['RevisionNumber'] ?? null),
        );
    }

    public function reviewDetail(string $assignmentPublicId): ?ReviewItemDetail
    {
        $row = $this->itemRow($assignmentPublicId);
        if ($row === null) {
            return null;
        }

        $assignmentId = AzureSqlValueMapper::intValue($row['AssignmentId'] ?? 0);
        $itemType = AzureSqlValueMapper::stringValue($row['ItemType'] ?? '');

        return new ReviewItemDetail(
            self::entry($row),
            ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            AzureSqlValueMapper::nullableString($row['Position'] ?? null),
            AzureSqlValueMapper::nullableString($row['Department'] ?? null),
            AzureSqlValueMapper::nullableString($row['Branch'] ?? null),
            AzureSqlValueMapper::nullableString($row['SubmittedAtUtc'] ?? null),
            AzureSqlValueMapper::nullableString($row['CompletedAtUtc'] ?? null),
            $this->history($assignmentPublicId),
            $itemType === 'form' ? $this->latestSubmission($assignmentId) : null,
            $itemType === 'training' ? $this->attempts($assignmentId) : [],
            $itemType === 'policy' ? $this->acknowledgedAt($assignmentId) : null,
        );
    }

    public function applyDecision(
        ReviewItemRecord $item,
        RowVersionToken $expectedRowVersion,
        StatusTransition $transition,
        OnboardingAdminEvent $event,
        ActorContext $actor,
    ): void {
        $this->runInTransaction($this->connection, function () use ($item, $expectedRowVersion, $transition, $event, $actor): void {
            // The token covers a second reviewer; the status covers the trainee
            // resubmitting in between. The status alone would miss a decision
            // and a resubmission that landed the item back where it was.
            $claimed = $this->connection->fetchAll(
                <<<'SQL'
                UPDATE [onboarding].[Assignments]
                SET [ReviewedByUserId] = :actorUserId,
                    [ReviewedAtUtc] = SYSUTCDATETIME(),
                    [UpdatedAtUtc] = SYSUTCDATETIME()
                OUTPUT INSERTED.[AssignmentId]
                WHERE [AssignmentId] = :assignmentId
                  AND [WithdrawnAtUtc] IS NULL
                  AND [Status] = :fromStatus
                  AND [RowVersion] = :expectedRowVersion
                SQL,
                [
                    ':actorUserId' => $actor->userId,
                    ':assignmentId' => $item->assignmentId,
                    ':fromStatus' => $transition->from->toString(),
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expectedRowVersion),
                ],
            );
            if ($claimed === []) {
                throw OnboardingWriteConflict::staleToken();
            }

            $this->applyTransition($this->connection, $item->assignmentId, $item->assignmentPublicId, $item->subjectUserId, $transition);
            if ($item->itemType === 'form' && $transition->to->toString() === AssignmentStatus::FIX_AND_RESUBMIT) {
                $this->reopenWithOrdinaryAnswers($item->assignmentId);
            }
            AzureSqlOnboardingAdminAuditLog::appendWithin($this->connection, $actor, $event);
        });
    }

    /**
     * A form sent back reopens with the person's last ordinary answers
     * (owner decision, 23 Sep 2026), so they change what the reviewer asked
     * about rather than typing the form again.
     *
     * Only the ordinary half: `SubmittedDataJson` never held the payroll or
     * restricted fields, and nothing here reads `[onboarding_hr]` to put them
     * back. The person enters those again, and the form says so
     * (`OnboardingFormDetail::$restrictedFieldsCleared`).
     *
     * A draft that already exists is left alone — it is newer than the
     * submission and it is theirs.
     */
    private function reopenWithOrdinaryAnswers(int $assignmentId): void
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1) fs.[SubmittedDataJson], fs.[FormDefinitionVersionId]
            FROM [onboarding].[FormSubmissions] fs
            WHERE fs.[AssignmentId] = :assignmentId
              AND NOT EXISTS (SELECT 1 FROM [onboarding].[FormDrafts] d WHERE d.[AssignmentId] = fs.[AssignmentId])
            ORDER BY fs.[RevisionNumber] DESC
            SQL,
            [':assignmentId' => $assignmentId],
        );
        if ($rows === []) {
            return;
        }

        // As in ReviewFieldProjection: a field classified payroll or restricted
        // is dropped by its classification, whatever the ordinary half holds.
        $ordinary = self::decodeObject($rows[0]['SubmittedDataJson'] ?? null);
        $classes = $this->classifications(AzureSqlValueMapper::intValue($rows[0]['FormDefinitionVersionId'] ?? 0));
        foreach (array_keys($ordinary) as $path) {
            $class = $classes[(string) $path] ?? null;
            if ($class !== null && in_array($class, DataClass::ALL, true) && DataClass::fromInt($class)->isRestricted()) {
                unset($ordinary[$path]);
            }
        }

        $this->connection->execute(
            'INSERT INTO [onboarding].[FormDrafts] ([AssignmentId], [DraftDataJson]) VALUES (:assignmentId, :draft)',
            [
                ':assignmentId' => $assignmentId,
                ':draft' => json_encode(
                    (object) FormPayloadSplitter::nest($ordinary),
                    JSON_THROW_ON_ERROR,
                ),
            ],
        );
    }

    // ------------------------------------------------------------------- reads

    /**
     * @return array<string, mixed>|null
     */
    private function itemRow(string $assignmentPublicId): ?array
    {
        // Checked before CONVERT: a malformed id is "no such item", not a
        // conversion error surfacing as a 500.
        if (preg_match(self::UUID, $assignmentPublicId) !== 1) {
            return null;
        }

        $columns = self::ITEM_COLUMNS;
        $joins = self::ITEM_JOINS;
        $waiting = self::WAITING_SINCE;

        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT TOP (1)
                {$columns},
                CONVERT(VARCHAR(33), {$waiting}, 127) AS [WaitingSinceUtc],
                CONVERT(VARBINARY(8), a.[RowVersion]) AS [RowVersion],
                CONVERT(VARCHAR(33), a.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
                CONVERT(VARCHAR(33), a.[CompletedAtUtc], 127) AS [CompletedAtUtc],
                t.[Position], t.[Department], t.[Branch]
            FROM [onboarding].[Assignments] a
            {$joins}
            WHERE a.[PublicId] = CONVERT(UNIQUEIDENTIFIER, :publicId)
              AND a.[WithdrawnAtUtc] IS NULL
            SQL,
            [':publicId' => $assignmentPublicId],
        );

        return $rows[0] ?? null;
    }

    /**
     * @return list<array{fromStatus: ?string, toStatus: string, actorType: string, actorDisplayName: ?string, note: ?string, atUtc: string}>
     */
    private function history(string $assignmentPublicId): array
    {
        $limit = self::HISTORY_LIMIT;
        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT TOP ({$limit})
                e.[FromStatus], e.[ToStatus], e.[ActorType],
                COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), u.[EmailAddress]) AS [ActorDisplayName],
                e.[Note],
                CONVERT(VARCHAR(33), e.[CreatedAtUtc], 127) AS [CreatedAtUtc]
            FROM [audit].[OnboardingStatusEvents] e
            LEFT JOIN [identity].[Users] u ON u.[UserId] = e.[ActorUserId]
            WHERE e.[AssignmentPublicId] = CONVERT(UNIQUEIDENTIFIER, :publicId)
            ORDER BY e.[CreatedAtUtc] DESC, e.[OnboardingStatusEventId] DESC
            SQL,
            [':publicId' => $assignmentPublicId],
        );

        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'fromStatus' => AzureSqlValueMapper::nullableString($row['FromStatus'] ?? null),
                'toStatus' => AzureSqlValueMapper::stringValue($row['ToStatus'] ?? ''),
                'actorType' => AzureSqlValueMapper::stringValue($row['ActorType'] ?? ''),
                'actorDisplayName' => AzureSqlValueMapper::nullableString($row['ActorDisplayName'] ?? null),
                'note' => AzureSqlValueMapper::nullableString($row['Note'] ?? null),
                'atUtc' => AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            ];
        }

        return $history;
    }

    /**
     * @return ?array{revisionNumber: int, submittedAtUtc: string, hasRestrictedData: bool, fields: list<array{path: string, label: string, value: ?string, withheld: bool}>}
     */
    private function latestSubmission(int $assignmentId): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                fs.[RevisionNumber],
                CONVERT(VARCHAR(33), fs.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
                fs.[SubmittedDataJson],
                fs.[HasRestrictedData],
                fs.[FormDefinitionVersionId],
                fdv.[SchemaJson]
            FROM [onboarding].[FormSubmissions] fs
            JOIN [onboarding].[FormDefinitionVersions] fdv
                ON fdv.[FormDefinitionVersionId] = fs.[FormDefinitionVersionId]
            WHERE fs.[AssignmentId] = :assignmentId
            ORDER BY fs.[RevisionNumber] DESC
            SQL,
            [':assignmentId' => $assignmentId],
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'revisionNumber' => AzureSqlValueMapper::intValue($row['RevisionNumber'] ?? 0),
            'submittedAtUtc' => AzureSqlValueMapper::stringValue($row['SubmittedAtUtc'] ?? ''),
            'hasRestrictedData' => AzureSqlValueMapper::boolValue($row['HasRestrictedData'] ?? false),
            'fields' => ReviewFieldProjection::project(
                self::decodeObject($row['SchemaJson'] ?? null),
                self::decodeObject($row['SubmittedDataJson'] ?? null),
                $this->classifications(AzureSqlValueMapper::intValue($row['FormDefinitionVersionId'] ?? 0)),
            ),
        ];
    }

    /**
     * Which class each field is — metadata, not data.
     *
     * @return array<string, int>
     */
    private function classifications(int $formDefinitionVersionId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT [FieldPath], [RetentionClassId]
            FROM [onboarding_hr].[FieldClassifications]
            WHERE [FormDefinitionVersionId] = :versionId
            SQL,
            [':versionId' => $formDefinitionVersionId],
        );

        $classes = [];
        foreach ($rows as $row) {
            $classes[AzureSqlValueMapper::stringValue($row['FieldPath'] ?? '')] = AzureSqlValueMapper::intValue($row['RetentionClassId'] ?? 0);
        }

        return $classes;
    }

    /**
     * Score and result per submitted attempt. Not the answers — see
     * {@see ReviewItemDetail}.
     *
     * @return list<array{attemptNumber: int, submittedAtUtc: ?string, scorePercent: ?int, isPass: ?bool}>
     */
    private function attempts(int $assignmentId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT [AttemptNumber],
                   CONVERT(VARCHAR(33), [SubmittedAtUtc], 127) AS [SubmittedAtUtc],
                   [ScorePercent], [IsPass]
            FROM [onboarding].[Attempts]
            WHERE [AssignmentId] = :assignmentId
              AND [SubmittedAtUtc] IS NOT NULL
            ORDER BY [AttemptNumber]
            SQL,
            [':assignmentId' => $assignmentId],
        );

        $attempts = [];
        foreach ($rows as $row) {
            $isPass = $row['IsPass'] ?? null;
            $attempts[] = [
                'attemptNumber' => AzureSqlValueMapper::intValue($row['AttemptNumber'] ?? 0),
                'submittedAtUtc' => AzureSqlValueMapper::nullableString($row['SubmittedAtUtc'] ?? null),
                'scorePercent' => AzureSqlValueMapper::nullableInt($row['ScorePercent'] ?? null),
                'isPass' => $isPass === null ? null : AzureSqlValueMapper::boolValue($isPass),
            ];
        }

        return $attempts;
    }

    private function acknowledgedAt(int $assignmentId): ?string
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT CONVERT(VARCHAR(33), MAX([AcknowledgedAtUtc]), 127) AS [AcknowledgedAtUtc]
            FROM [onboarding].[PolicyAcknowledgements]
            WHERE [AssignmentId] = :assignmentId
            SQL,
            [':assignmentId' => $assignmentId],
        );

        return AzureSqlValueMapper::nullableString($rows[0]['AcknowledgedAtUtc'] ?? null);
    }

    private function countQueue(): int
    {
        $queued = self::queuedStatusList();
        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT COUNT(*) AS [Total]
            FROM [onboarding].[Assignments] a
            WHERE a.[WithdrawnAtUtc] IS NULL AND a.[Status] IN ({$queued})
            SQL,
        );

        return AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $row
     */
    private static function entry(array $row): ReviewQueueEntry
    {
        $itemType = AzureSqlValueMapper::stringValue($row['ItemType'] ?? '');

        return new ReviewQueueEntry(
            strtolower(AzureSqlValueMapper::stringValue($row['AssignmentPublicId'] ?? '')),
            AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            strtolower(AzureSqlValueMapper::stringValue($row['PersonPublicId'] ?? '')),
            AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
            $itemType === 'policy' || $itemType === 'form' ? $itemType : 'training',
            strtolower(AzureSqlValueMapper::stringValue($row['ItemPublicId'] ?? '')),
            AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
            AssignmentStatus::fromStorage($row['Status'] ?? null),
            AzureSqlValueMapper::nullableString($row['WaitingSinceUtc'] ?? null),
            AzureSqlValueMapper::nullableString($row['DueDate'] ?? null),
            AzureSqlValueMapper::nullableInt($row['RevisionNumber'] ?? null),
        );
    }

    /**
     * `N'need_help', N'submitted'` — literals from the domain's own constants,
     * never from a request.
     */
    private static function queuedStatusList(): string
    {
        return implode(', ', array_map(
            static fn (AssignmentStatus $s): string => "N'" . $s->toString() . "'",
            AssignmentStatus::needsReviewSet(),
        ));
    }

    private static function queueOrderCase(): string
    {
        $whens = '';
        foreach (AssignmentStatus::all() as $status) {
            $whens .= sprintf(" WHEN N'%s' THEN %d", $status->toString(), $status->queueOrder());
        }

        return 'CASE a.[Status]' . $whens . ' ELSE 99 END';
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
