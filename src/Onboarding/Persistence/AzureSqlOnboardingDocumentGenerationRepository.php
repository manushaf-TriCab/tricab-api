<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\PolicyDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;

/**
 * Writes `[onboarding].[DocumentReferences]` (5.4). The table has no trigger, so
 * the new public id comes straight from `OUTPUT`.
 *
 * Every lookup is by the person's own user id as well as the assignment, as on
 * the read path: a generation request cannot be pointed at somebody else's
 * submission.
 */
final class AzureSqlOnboardingDocumentGenerationRepository implements OnboardingDocumentGenerationRepository
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function findLatestFormSubmission(int $userId, string $assignmentPublicId): ?FormSubmissionForDocument
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                fs.[FormSubmissionId],
                fs.[AssignmentId],
                fs.[UserId],
                fd.[Slug],
                fd.[AccessGroup],
                fs.[RevisionNumber],
                CONVERT(VARCHAR(33), fs.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
                COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), N'') AS [DisplayName]
            FROM [onboarding].[FormSubmissions] fs
            JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = fs.[AssignmentId]
            JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = fs.[FormDefinitionVersionId]
            JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
            JOIN [identity].[Users] u ON u.[UserId] = fs.[UserId]
            WHERE a.[PublicId] = CONVERT(UNIQUEIDENTIFIER, :assignment)
              AND a.[UserId] = :userId
              AND fs.[UserId] = :submitter
            ORDER BY fs.[RevisionNumber] DESC
            SQL,
            [':assignment' => $assignmentPublicId, ':userId' => $userId, ':submitter' => $userId],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return new FormSubmissionForDocument(
            AzureSqlValueMapper::intValue($row['FormSubmissionId'] ?? 0),
            AzureSqlValueMapper::intValue($row['AssignmentId'] ?? 0),
            AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
            AzureSqlValueMapper::stringValue($row['AccessGroup'] ?? ''),
            AzureSqlValueMapper::intValue($row['RevisionNumber'] ?? 0),
            AzureSqlValueMapper::stringValue($row['SubmittedAtUtc'] ?? ''),
            AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
        );
    }

    public function hasDocumentForFormSubmission(int $formSubmissionId): bool
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (1) 1 AS [Found] FROM [onboarding].[DocumentReferences] WHERE [FormSubmissionId] = :id AND [DeletedAtUtc] IS NULL',
            [':id' => $formSubmissionId],
        );

        return $rows !== [];
    }

    public function recordFormDocument(
        FormSubmissionForDocument $submission,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            INSERT INTO [onboarding].[DocumentReferences]
                ([UserId], [AssignmentId], [FormSubmissionId], [DocumentKind], [RetentionClassId],
                 [BlobContainer], [BlobPath], [ContentType], [ByteSize], [ContentSha256])
            OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
            VALUES (:userId, :assignmentId, :submissionId, :kind, :class, :container, :path, :contentType, :size, :sha)
            SQL,
            [
                ':userId' => $submission->userId,
                ':assignmentId' => $submission->assignmentId,
                ':submissionId' => $submission->formSubmissionId,
                ':kind' => $document->documentKind,
                ':class' => $dataClass->toInt(),
                ':container' => $container,
                ':path' => $blobPath,
                ':contentType' => $document->contentType,
                ':size' => $document->byteSize(),
                ':sha' => $document->sha256,
            ],
        );

        return strtolower(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
    }

    public function findAssessmentContext(int $userId, string $assignmentPublicId): ?AssessmentDocumentContext
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                a.[AssignmentId],
                a.[UserId],
                tm.[Slug],
                tm.[Title],
                COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), N'') AS [PersonName],
                COALESCE(t.[Position], N'') AS [Position],
                COALESCE(t.[Department], N'') AS [Department],
                COALESCE(t.[Branch], N'') AS [Branch],
                COALESCE(NULLIF(LTRIM(RTRIM(m.[DisplayName])), N''), N'') AS [ManagerName]
            FROM [onboarding].[Assignments] a
            JOIN [onboarding].[TrainingModuleVersions] tmv ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
            JOIN [onboarding].[TrainingModules] tm ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
            JOIN [identity].[Users] u ON u.[UserId] = a.[UserId]
            LEFT JOIN [onboarding].[TraineeProfiles] t ON t.[UserId] = a.[UserId]
            LEFT JOIN [identity].[Users] m ON m.[UserId] = t.[ManagerUserId]
            WHERE a.[PublicId] = CONVERT(UNIQUEIDENTIFIER, :assignment)
              AND a.[UserId] = :userId
              AND a.[ItemType] = N'training'
            SQL,
            [':assignment' => $assignmentPublicId, ':userId' => $userId],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return new AssessmentDocumentContext(
            AzureSqlValueMapper::intValue($row['AssignmentId'] ?? 0),
            AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
            AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            AzureSqlValueMapper::stringValue($row['PersonName'] ?? ''),
            AzureSqlValueMapper::stringValue($row['Position'] ?? ''),
            AzureSqlValueMapper::stringValue($row['Department'] ?? ''),
            AzureSqlValueMapper::stringValue($row['Branch'] ?? ''),
            AzureSqlValueMapper::stringValue($row['ManagerName'] ?? ''),
        );
    }

    public function userDisplayName(int $userId): string
    {
        $rows = $this->connection->fetchAll(
            'SELECT COALESCE(NULLIF(LTRIM(RTRIM([DisplayName])), N\'\'), N\'\') AS [DisplayName] FROM [identity].[Users] WHERE [UserId] = :userId',
            [':userId' => $userId],
        );

        return AzureSqlValueMapper::stringValue($rows[0]['DisplayName'] ?? '');
    }

    public function hasDocumentAtPath(string $blobPath): bool
    {
        return $this->connection->fetchAll(
            'SELECT TOP (1) 1 AS [Found] FROM [onboarding].[DocumentReferences] WHERE [BlobPath] = :path',
            [':path' => $blobPath],
        ) !== [];
    }

    public function recordAssessmentDocument(
        AssessmentDocumentContext $context,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            INSERT INTO [onboarding].[DocumentReferences]
                ([UserId], [AssignmentId], [FormSubmissionId], [DocumentKind], [RetentionClassId],
                 [BlobContainer], [BlobPath], [ContentType], [ByteSize], [ContentSha256])
            OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
            VALUES (:userId, :assignmentId, NULL, :kind, :class, :container, :path, :contentType, :size, :sha)
            SQL,
            [
                ':userId' => $context->userId,
                ':assignmentId' => $context->assignmentId,
                ':kind' => $document->documentKind,
                ':class' => $dataClass->toInt(),
                ':container' => $container,
                ':path' => $blobPath,
                ':contentType' => $document->contentType,
                ':size' => $document->byteSize(),
                ':sha' => $document->sha256,
            ],
        );

        return strtolower(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
    }

    public function findLatestFormSubmissionByAssignment(string $assignmentPublicId): ?FormSubmissionForDocument
    {
        $owner = $this->connection->fetchAll(
            'SELECT TOP (1) [UserId] FROM [onboarding].[Assignments] WHERE [PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :assignment)',
            [':assignment' => $assignmentPublicId],
        );

        return $owner === [] ? null : $this->findLatestFormSubmission(AzureSqlValueMapper::intValue($owner[0]['UserId'] ?? 0), $assignmentPublicId);
    }

    public function ordinaryAnswers(int $formSubmissionId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [SubmittedDataJson] FROM [onboarding].[FormSubmissions] WHERE [FormSubmissionId] = :id',
            [':id' => $formSubmissionId],
        );
        $decoded = json_decode(AzureSqlValueMapper::stringValue($rows[0]['SubmittedDataJson'] ?? '{}'), true);
        $answers = [];
        foreach (is_array($decoded) ? $decoded : [] as $path => $value) {
            if (is_string($path)) {
                $answers[$path] = $value;
            }
        }

        return $answers;
    }

    public function recordPhoto(FormContributionContext $form, RenderedDocument $photo, string $container, string $blobPath): string
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            INSERT INTO [onboarding].[DocumentReferences]
                ([UserId], [AssignmentId], [FormSubmissionId], [DocumentKind], [RetentionClassId],
                 [BlobContainer], [BlobPath], [ContentType], [ByteSize], [ContentSha256])
            OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
            VALUES (:userId, :assignmentId, NULL, N'photo', :class, :container, :path, :contentType, :size, :sha)
            SQL,
            [
                ':userId' => $form->subjectUserId,
                ':assignmentId' => $form->assignmentId,
                ':class' => DataClass::ORDINARY_HR,
                ':container' => $container,
                ':path' => $blobPath,
                ':contentType' => $photo->contentType,
                ':size' => $photo->byteSize(),
                ':sha' => $photo->sha256,
            ],
        );

        return strtolower(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
    }

    public function photosOf(int $assignmentId, array $documentPublicIds): array
    {
        $ids = array_values(array_filter($documentPublicIds, static fn (string $id): bool => preg_match('/^[0-9a-f-]{36}$/i', $id) === 1));
        if ($ids === []) {
            return [];
        }
        $params = [':assignmentId' => $assignmentId];
        $markers = [];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
            $markers[] = 'TRY_CONVERT(UNIQUEIDENTIFIER, :id' . $i . ')';
        }
        $rows = $this->connection->fetchAll(
            sprintf(
                "SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId] FROM [onboarding].[DocumentReferences]
                 WHERE [AssignmentId] = :assignmentId AND [DocumentKind] = N'photo' AND [DeletedAtUtc] IS NULL AND [PublicId] IN (%s)",
                implode(', ', $markers),
            ),
            $params,
        );

        return array_map(static fn (array $row): string => strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')), $rows);
    }

    public function findPolicyDocumentContext(int $userId, string $assignmentPublicId): ?PolicyDocumentContext
    {
        $column = $this->connection->fetchAll(
            "SELECT COL_LENGTH(N'[onboarding].[PolicyAcknowledgements]', N'DeclarationJson') AS [Length]",
        );
        if ($column === [] || ($column[0]['Length'] ?? null) === null) {
            return null;
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                a.[AssignmentId],
                a.[UserId],
                CONVERT(NVARCHAR(36), ack.[PublicId]) AS [AcknowledgementPublicId],
                p.[Slug],
                p.[Title],
                COALESCE(pv.[DocumentReference], N'') AS [DocumentReference],
                CONVERT(VARCHAR(33), ack.[AcknowledgedAtUtc], 127) AS [AcknowledgedAtUtc],
                ack.[DeclarationJson]
            FROM [onboarding].[Assignments] a
            JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
            JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
            JOIN [onboarding].[PolicyAcknowledgements] ack
                ON ack.[UserId] = a.[UserId]
               AND ack.[PolicyVersionId] = pv.[PolicyVersionId]
            WHERE a.[PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :assignment)
              AND a.[UserId] = :userId
              AND a.[ItemType] = N'policy'
              AND ack.[DeclarationJson] IS NOT NULL
            SQL,
            [':assignment' => $assignmentPublicId, ':userId' => $userId],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $declaration = PolicyDeclaration::fromStoredJson(AzureSqlValueMapper::nullableString($row['DeclarationJson'] ?? null));
        if ($declaration === null) {
            return null;
        }

        return new PolicyDocumentContext(
            AzureSqlValueMapper::intValue($row['AssignmentId'] ?? 0),
            AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            strtolower(AzureSqlValueMapper::stringValue($row['AcknowledgementPublicId'] ?? '')),
            AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
            AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            AzureSqlValueMapper::stringValue($row['DocumentReference'] ?? ''),
            AzureSqlValueMapper::stringValue($row['AcknowledgedAtUtc'] ?? ''),
            $declaration,
        );
    }

    public function recordPolicyDocument(
        PolicyDocumentContext $context,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            INSERT INTO [onboarding].[DocumentReferences]
                ([UserId], [AssignmentId], [FormSubmissionId], [DocumentKind], [RetentionClassId],
                 [BlobContainer], [BlobPath], [ContentType], [ByteSize], [ContentSha256])
            OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
            VALUES (:userId, :assignmentId, NULL, :kind, :class, :container, :path, :contentType, :size, :sha)
            SQL,
            [
                ':userId' => $context->userId,
                ':assignmentId' => $context->assignmentId,
                ':kind' => $document->documentKind,
                ':class' => $dataClass->toInt(),
                ':container' => $container,
                ':path' => $blobPath,
                ':contentType' => $document->contentType,
                ':size' => $document->byteSize(),
                ':sha' => $document->sha256,
            ],
        );

        return strtolower(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
    }
}
