<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentView;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Generated documents, against `[onboarding].[DocumentReferences]` and
 * `[audit].[OnboardingDocumentAccessEvents]`.
 *
 * ## `[DeletedAtUtc] IS NULL`, on both statements
 *
 * A deleted document is gone from the list and unfindable by id, and the two
 * predicates are the same so that a person cannot open something that is no
 * longer offered to them. Retention is the thing that would set that column, and
 * the figure has not been decided — so today nothing sets it, and the predicate
 * is the part that has to already be right when something does.
 *
 * ## The blob path is selected here and nowhere near a response
 *
 * {@see findDocument()} returns {@see OnboardingDocumentRef}, which has no
 * `toArray()`. {@see listDocuments()} returns {@see OnboardingDocumentView},
 * which has one and does not carry a path. The split is the reason a container
 * and a path cannot reach a browser by accident.
 */
final class AzureSqlOnboardingDocumentRepository implements OnboardingDocumentRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listDocuments(int $userId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT
                CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
                d.[DocumentKind],
                d.[RetentionClassId],
                d.[ContentType],
                d.[ByteSize],
                CONVERT(VARCHAR(33), d.[GeneratedAtUtc], 127) AS [GeneratedAtUtc],
                CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssignmentPublicId]
            FROM [onboarding].[DocumentReferences] d
            LEFT JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = d.[AssignmentId]
            WHERE d.[UserId] = :userId
              AND d.[DeletedAtUtc] IS NULL
            ORDER BY d.[GeneratedAtUtc] DESC, d.[DocumentReferenceId] DESC
            SQL,
            [':userId' => $userId],
        );

        $views = [];
        foreach ($rows as $row) {
            $views[] = new OnboardingDocumentView(
                publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                documentKind: AzureSqlValueMapper::stringValue($row['DocumentKind'] ?? ''),
                dataClass: self::dataClass($row['RetentionClassId'] ?? null),
                contentType: AzureSqlValueMapper::stringValue($row['ContentType'] ?? ''),
                byteSize: AzureSqlValueMapper::intValue($row['ByteSize'] ?? 0),
                generatedAtUtc: AzureSqlValueMapper::stringValue($row['GeneratedAtUtc'] ?? ''),
                assignmentPublicId: AzureSqlValueMapper::nullableString($row['AssignmentPublicId'] ?? null),
            );
        }

        return $views;
    }

    public function findDocument(int $userId, string $documentPublicId): ?OnboardingDocumentRef
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
                d.[UserId],
                d.[DocumentKind],
                d.[RetentionClassId],
                d.[BlobContainer],
                d.[BlobPath],
                d.[ContentType],
                d.[ByteSize],
                d.[ContentSha256],
                CONVERT(VARCHAR(33), d.[GeneratedAtUtc], 127) AS [GeneratedAtUtc]
            FROM [onboarding].[DocumentReferences] d
            WHERE d.[UserId] = :userId
              AND CONVERT(NVARCHAR(36), d.[PublicId]) = :documentPublicId
              AND d.[DeletedAtUtc] IS NULL
            SQL,
            [':userId' => $userId, ':documentPublicId' => $documentPublicId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return new OnboardingDocumentRef(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            ownerUserId: AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            documentKind: AzureSqlValueMapper::stringValue($row['DocumentKind'] ?? ''),
            dataClass: self::dataClass($row['RetentionClassId'] ?? null),
            blobContainer: AzureSqlValueMapper::stringValue($row['BlobContainer'] ?? ''),
            blobPath: AzureSqlValueMapper::stringValue($row['BlobPath'] ?? ''),
            contentType: AzureSqlValueMapper::stringValue($row['ContentType'] ?? ''),
            byteSize: AzureSqlValueMapper::intValue($row['ByteSize'] ?? 0),
            contentSha256: AzureSqlValueMapper::stringValue($row['ContentSha256'] ?? ''),
            generatedAtUtc: AzureSqlValueMapper::stringValue($row['GeneratedAtUtc'] ?? ''),
        );
    }

    public function recordAccess(
        OnboardingDocumentRef $document,
        int $actorUserId,
        string $accessKind,
        string $permissionCode,
        ?string $correlationId,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->connection->execute(
            <<<'SQL'
            INSERT INTO [audit].[OnboardingDocumentAccessEvents] (
                [DocumentPublicId], [SubjectUserId], [ActorUserId], [RetentionClassId],
                [AccessKind], [PermissionCode], [RequestCorrelationId], [IpAddress], [UserAgent]
            ) VALUES (
                CONVERT(UNIQUEIDENTIFIER, :documentPublicId), :subjectUserId, :actorUserId, :retentionClassId,
                :accessKind, :permissionCode, :correlationId, :ipAddress, :userAgent
            )
            SQL,
            [
                ':documentPublicId' => $document->publicId,
                // Subject and actor are the same person on the self-service
                // route and will not be on Phase 4's. Both are written, because
                // an audit table that inferred one from the other would be
                // useless for exactly the reads worth auditing.
                ':subjectUserId' => $document->ownerUserId,
                ':actorUserId' => $actorUserId,
                ':retentionClassId' => $document->dataClass->toInt(),
                ':accessKind' => $accessKind,
                ':permissionCode' => $permissionCode,
                ':correlationId' => $correlationId,
                ':ipAddress' => $ipAddress,
                // NVARCHAR(500). A user agent longer than that is a client
                // being strange, and truncating is better than losing the row
                // the read is supposed to be recorded in.
                ':userAgent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            ],
        );
    }

    /**
     * Forgiving, on the trainee-facing side, in the safe direction: a class the
     * application does not recognise is read as C5, and
     * {@see \TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy}
     * streams C5. Being wrong here costs bandwidth; being wrong the other way
     * hands out a bearer URL.
     */
    private static function dataClass(mixed $value): DataClass
    {
        $id = AzureSqlValueMapper::intValue($value);

        return in_array($id, DataClass::ALL, true)
            ? DataClass::fromInt($id)
            : DataClass::generatedDocument();
    }
}
