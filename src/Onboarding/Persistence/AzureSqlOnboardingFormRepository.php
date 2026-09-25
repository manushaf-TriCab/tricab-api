<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Api\Product\Persistence\UniqueConstraintTranslator;
use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Application\DTO\SplitFormPayload;
use TriCab\Core\Onboarding\Contract\OnboardingFormRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Forms, drafts and submissions, across `[onboarding]` and `[onboarding_hr]`.
 *
 * ## This is the only class that writes to both schemas
 *
 * And it writes different halves to each, in one transaction: the ordinary
 * remainder to `onboarding.FormSubmissions`, the payroll and restricted halves
 * to `onboarding_hr.RestrictedSubmissionData`, keyed by the submission's public
 * id. {@see submitForm()} takes a {@see SplitFormPayload} rather than an array,
 * so there is no signature through which an unsplit payload could arrive.
 *
 * The two restricted classes go to two rows, not one —
 * `UQ_onboarding_hr_RestrictedSubmissionData_SubmissionClass` is on
 * (submission, class) — so the C3 half and the C4 half stay separable without
 * re-reading and re-classifying them.
 *
 * ## The row version, and the one place it belongs
 *
 * Only `FormDrafts` carries one. The pattern is Phase 2.5's and
 * `AzureSqlExpensePeriodRepository`'s: bind through {@see SqlBinary} via
 * {@see ProductRowVersionMapper::toSqlBinary()}, `UPDATE … OUTPUT INSERTED.* …
 * WHERE [RowVersion] = :expected`, and an empty result IS the conflict. Not a
 * hex string, and not `execute()` plus a row count, which
 * {@see SqlConnection::execute()} cannot give.
 */
final class AzureSqlOnboardingFormRepository implements OnboardingFormRepository
{
    use AzureSqlTransaction;
    use AzureSqlOnboardingStatusWriter;
    use UniqueConstraintTranslator;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly AzureSqlOnboardingAssignmentLocator $locator,
    ) {
    }

    public function findForm(int $userId, string $assignmentPublicId): ?OnboardingFormDetail
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssignmentPublicId],
                fd.[Title],
                CONVERT(NVARCHAR(36), fdv.[PublicId]) AS [VersionPublicId],
                fdv.[VersionNumber],
                fdv.[SchemaJson],
                a.[Status],
                dr.[DraftDataJson],
                CONVERT(VARBINARY(8), dr.[RowVersion]) AS [DraftRowVersion],
                CONVERT(VARCHAR(33), dr.[UpdatedAtUtc], 127) AS [DraftUpdatedAtUtc],
                (
                    SELECT COUNT(*)
                    FROM [onboarding].[FormSubmissions] fs
                    WHERE fs.[AssignmentId] = a.[AssignmentId]
                ) AS [SubmittedRevisionCount],
                (
                    SELECT CONVERT(VARCHAR(33), MAX(fs.[SubmittedAtUtc]), 127)
                    FROM [onboarding].[FormSubmissions] fs
                    WHERE fs.[AssignmentId] = a.[AssignmentId]
                ) AS [LastSubmittedAtUtc],
                /* The reviewer's reason for sending it back, only while it is
                   sent back (4.3). The latest such event, not any: a form sent
                   back twice owes the person the second reason. */
                CASE WHEN a.[Status] = N'fix_and_resubmit' THEN (
                    SELECT TOP (1) e.[Note]
                    FROM [audit].[OnboardingStatusEvents] e
                    WHERE e.[AssignmentPublicId] = a.[PublicId]
                      AND e.[ActorType] = N'reviewer'
                      AND e.[ToStatus] = N'fix_and_resubmit'
                    ORDER BY e.[CreatedAtUtc] DESC, e.[OnboardingStatusEventId] DESC
                ) END AS [ReviewerNote],
                CASE WHEN a.[Status] = N'fix_and_resubmit' AND (
                    SELECT TOP (1) fs.[HasRestrictedData]
                    FROM [onboarding].[FormSubmissions] fs
                    WHERE fs.[AssignmentId] = a.[AssignmentId]
                    ORDER BY fs.[RevisionNumber] DESC
                ) = 1 THEN 1 ELSE 0 END AS [RestrictedFieldsCleared]
            FROM [onboarding].[Assignments] a
            JOIN [onboarding].[FormDefinitionVersions] fdv
                ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
            JOIN [onboarding].[FormDefinitions] fd
                ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
            LEFT JOIN [onboarding].[FormDrafts] dr ON dr.[AssignmentId] = a.[AssignmentId]
            WHERE a.[UserId] = :userId
              AND CONVERT(NVARCHAR(36), a.[PublicId]) = :assignmentPublicId
              AND a.[ItemType] = N'form'
              AND a.[WithdrawnAtUtc] IS NULL
            SQL,
            [':userId' => $userId, ':assignmentPublicId' => $assignmentPublicId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $rawToken = $row['DraftRowVersion'] ?? null;

        return new OnboardingFormDetail(
            assignmentPublicId: AzureSqlValueMapper::stringValue($row['AssignmentPublicId'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            versionPublicId: AzureSqlValueMapper::stringValue($row['VersionPublicId'] ?? ''),
            versionNumber: AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
            status: AssignmentStatus::fromStorage($row['Status'] ?? null),
            schema: self::decodeObject($row['SchemaJson'] ?? null),
            draftData: self::decodeObject($row['DraftDataJson'] ?? null),
            draftRowVersion: $rawToken === null ? null : ProductRowVersionMapper::toToken($rawToken)->toBase64(),
            draftUpdatedAtUtc: AzureSqlValueMapper::nullableString($row['DraftUpdatedAtUtc'] ?? null),
            submittedRevisionCount: AzureSqlValueMapper::intValue($row['SubmittedRevisionCount'] ?? 0),
            lastSubmittedAtUtc: AzureSqlValueMapper::nullableString($row['LastSubmittedAtUtc'] ?? null),
            reviewerNote: AzureSqlValueMapper::nullableString($row['ReviewerNote'] ?? null),
            restrictedFieldsCleared: AzureSqlValueMapper::boolValue($row['RestrictedFieldsCleared'] ?? false),
        );
    }

    public function fieldClassifications(string $formVersionPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT fc.[FieldPath], fc.[RetentionClassId]
            FROM [onboarding_hr].[FieldClassifications] fc
            JOIN [onboarding].[FormDefinitionVersions] fdv
                ON fdv.[FormDefinitionVersionId] = fc.[FormDefinitionVersionId]
            WHERE CONVERT(NVARCHAR(36), fdv.[PublicId]) = :versionPublicId
            SQL,
            [':versionPublicId' => $formVersionPublicId],
        );

        $classifications = [];
        foreach ($rows as $row) {
            $path = AzureSqlValueMapper::stringValue($row['FieldPath'] ?? '');

            if ($path !== '') {
                $classifications[$path] = AzureSqlValueMapper::intValue($row['RetentionClassId'] ?? 0);
            }
        }

        // An empty map is returned as an empty map, and the splitter then refuses
        // every field on the form. That is the fail-closed behaviour rather than
        // a gap: a form version nobody classified cannot be submitted.
        return $classifications;
    }

    public function saveDraft(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        array $draftData,
        ?RowVersionToken $expectedRowVersion,
        ?StatusTransition $transition,
    ): OnboardingFormDetail {
        $located = $this->locate($userId, $assignmentPublicId, $expectedVersionPublicId);
        $json = json_encode((object) $draftData, JSON_THROW_ON_ERROR);

        $this->runInTransaction($this->connection, function () use (
            $located,
            $assignmentPublicId,
            $userId,
            $json,
            $expectedRowVersion,
            $transition,
        ): void {
            if ($expectedRowVersion === null) {
                $this->insertDraft($located['assignmentId'], $json);
            } else {
                $this->updateDraft($located['assignmentId'], $json, $expectedRowVersion);
            }

            $this->applyTransition(
                $this->connection,
                $located['assignmentId'],
                $assignmentPublicId,
                $userId,
                $transition,
            );
        });

        return $this->reread($userId, $assignmentPublicId);
    }

    public function submitForm(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        SplitFormPayload $payload,
        ?string $idempotencyKey,
        ?StatusTransition $transition,
    ): OnboardingFormDetail {
        $located = $this->locate($userId, $assignmentPublicId, $expectedVersionPublicId);

        $this->runInTransaction($this->connection, function () use (
            $located,
            $assignmentPublicId,
            $userId,
            $payload,
            $idempotencyKey,
            $transition,
        ): void {
            $submissionPublicId = $this->insertSubmission($located, $userId, $payload, $idempotencyKey);
            $this->insertRestricted($submissionPublicId, $userId, $payload);

            /* The draft is scratch and its lifetime ends here. Keeping it beside
               an immutable submission is how the two come to disagree, and a
               person reopening a submitted form should see what they submitted
               rather than what they were typing before they submitted it. */
            $this->connection->execute(
                'DELETE FROM [onboarding].[FormDrafts] WHERE [AssignmentId] = :assignmentId',
                [':assignmentId' => $located['assignmentId']],
            );

            $this->applyTransition(
                $this->connection,
                $located['assignmentId'],
                $assignmentPublicId,
                $userId,
                $transition,
            );
        });

        return $this->reread($userId, $assignmentPublicId);
    }

    // ------------------------------------------------------------- internals

    /**
     * @return array{assignmentId: int, versionId: int, status: string, versionPublicId: string}
     */
    private function locate(int $userId, string $assignmentPublicId, string $expectedVersionPublicId): array
    {
        $located = $this->locator->locate($userId, $assignmentPublicId, 'form', $expectedVersionPublicId);

        if ($located !== null) {
            return $located;
        }

        if ($this->locator->existsForPerson($userId, $assignmentPublicId, 'form')) {
            throw ContentVersionSuperseded::reload();
        }

        throw OnboardingAssignmentNotFound::forThisPerson();
    }

    private function reread(int $userId, string $assignmentPublicId): OnboardingFormDetail
    {
        $detail = $this->findForm($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        return $detail;
    }

    private function insertDraft(int $assignmentId, string $json): void
    {
        /* No token means "there is no row". If there is one, the unique
           constraint says so, and that is reported as a stale token rather than
           as a duplicate: from the client's point of view it is the same event —
           somebody else saved first. */
        $this->translatingUniqueConstraintViolation(
            function () use ($assignmentId, $json): void {
                $this->connection->execute(
                    <<<'SQL'
                    INSERT INTO [onboarding].[FormDrafts] ([AssignmentId], [DraftDataJson])
                    VALUES (:assignmentId, :draftData)
                    SQL,
                    [':assignmentId' => $assignmentId, ':draftData' => $json],
                );
            },
            static fn (): Throwable => OnboardingWriteConflict::staleToken(),
        );
    }

    private function updateDraft(int $assignmentId, string $json, RowVersionToken $expected): void
    {
        $updated = $this->connection->fetchAll(
            <<<'SQL'
            UPDATE [onboarding].[FormDrafts]
            SET [DraftDataJson] = :draftData,
                [UpdatedAtUtc] = SYSUTCDATETIME()
            OUTPUT INSERTED.[FormDraftId]
            WHERE [AssignmentId] = :assignmentId
              AND [RowVersion] = :expectedRowVersion
            SQL,
            [
                ':draftData' => $json,
                ':assignmentId' => $assignmentId,
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expected),
            ],
        );

        if ($updated === []) {
            throw OnboardingWriteConflict::staleToken();
        }
    }

    /**
     * @param array{assignmentId: int, versionId: int, status: string, versionPublicId: string} $located
     *
     * @return string the new submission's public id
     */
    private function insertSubmission(
        array $located,
        int $userId,
        SplitFormPayload $payload,
        ?string $idempotencyKey,
    ): string {
        /* The revision number is allocated from the rows that exist at this
           moment, inside the transaction. Choosing it in PHP would race every
           other submission for the same assignment, and
           UQ_onboarding_FormSubmissions_Revision would turn that into a 500. */
        $rows = $this->translatingUniqueConstraintViolation(
            fn (): array => $this->connection->fetchAll(
                <<<'SQL'
                INSERT INTO [onboarding].[FormSubmissions] (
                    [AssignmentId], [UserId], [FormDefinitionVersionId], [RevisionNumber],
                    [SubmittedDataJson], [HasRestrictedData], [IdempotencyKey]
                )
                OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
                SELECT
                    :assignmentId,
                    :userId,
                    :versionId,
                    COALESCE(MAX(prior.[RevisionNumber]), 0) + 1,
                    :submittedData,
                    :hasRestricted,
                    :idempotencyKey
                FROM [onboarding].[FormSubmissions] prior
                WHERE prior.[AssignmentId] = :assignmentIdForRevision
                SQL,
                [
                    ':assignmentId' => $located['assignmentId'],
                    ':userId' => $userId,
                    ':versionId' => $located['versionId'],
                    // The ordinary remainder ONLY. Everything classified payroll
                    // or restricted is in the next statement, in another schema.
                    ':submittedData' => json_encode((object) $payload->ordinary, JSON_THROW_ON_ERROR),
                    ':hasRestricted' => $payload->hasRestrictedData() ? 1 : 0,
                    ':idempotencyKey' => $idempotencyKey,
                    ':assignmentIdForRevision' => $located['assignmentId'],
                ],
            ),
            static fn (): Throwable => OnboardingWriteConflict::alreadyRecorded('form submission'),
        );

        $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');

        if ($publicId === '') {
            throw OnboardingWriteConflict::alreadyRecorded('form submission');
        }

        return $publicId;
    }

    private function insertRestricted(string $submissionPublicId, int $userId, SplitFormPayload $payload): void
    {
        foreach ([
            DataClass::PAYROLL => $payload->payroll,
            DataClass::RESTRICTED_HR => $payload->restricted,
        ] as $classId => $fields) {
            if ($fields === []) {
                continue;
            }

            $this->connection->execute(
                <<<'SQL'
                INSERT INTO [onboarding_hr].[RestrictedSubmissionData]
                    ([FormSubmissionPublicId], [UserId], [RetentionClassId], [PayloadJson], [FieldCount])
                VALUES (
                    CONVERT(UNIQUEIDENTIFIER, :submissionPublicId), :userId, :classId, :payload, :fieldCount
                )
                SQL,
                [
                    ':submissionPublicId' => $submissionPublicId,
                    ':userId' => $userId,
                    ':classId' => $classId,
                    ':payload' => json_encode((object) $fields, JSON_THROW_ON_ERROR),
                    ':fieldCount' => count($fields),
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(mixed $json): array
    {
        $text = AzureSqlValueMapper::nullableString($json);
        /** @var mixed $decoded */
        $decoded = $text === null ? [] : json_decode($text, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
