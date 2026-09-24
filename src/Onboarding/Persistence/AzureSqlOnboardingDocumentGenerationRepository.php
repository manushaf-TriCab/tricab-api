<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

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
}
