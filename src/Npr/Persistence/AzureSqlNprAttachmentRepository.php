<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Npr\Contract\NprAttachmentRepository;
use TriCab\Core\Npr\Domain\NewNprAttachment;
use TriCab\Core\Npr\Domain\NprAttachment;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Shared\Time\Clock;

/**
 * Attachment metadata in `[npr].[Attachments]`, the bytes in Blob storage under
 * `BlobKey`. Every add and every removal writes its `[npr].[Activity]` row in the
 * same transaction. A removal sets `IsDeleted`; the row and the blob stay.
 */
final class AzureSqlNprAttachmentRepository implements NprAttachmentRepository
{
    use AzureSqlTransaction;

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const SELECT = 'SELECT a.[PublicId], r.[PublicId] AS [RequestPublicId], a.[OriginalFilename], a.[BlobKey],
            a.[FileSizeBytes], a.[MimeType], a.[DocumentType], a.[Title], a.[UploadedByUserId], a.[UploadedByEmail],
            u.[DisplayName] AS [UploadedByName], a.[UploadedAt]
        FROM [npr].[Attachments] a
        INNER JOIN [npr].[Requests] r ON r.[RequestId] = a.[RequestId]
        LEFT JOIN [identity].[Users] u ON u.[UserId] = a.[UploadedByUserId]';

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly Clock $clock,
    ) {
    }

    public function add(string $requestPublicId, NewNprAttachment $new, string $actorRole): ?NprAttachment
    {
        $id = self::guid($requestPublicId);
        if ($id === null) {
            return null;
        }
        $now = AzureSqlValueMapper::formatDateTime($this->clock->now());

        $publicId = $this->runInTransaction($this->connection, function () use ($id, $new, $actorRole, $now): ?string {
            $request = $this->lockRequest($id);
            if ($request === null) {
                return null;
            }
            $row = $this->connection->fetchAll(
                'INSERT INTO [npr].[Attachments]
                    ([RequestId], [OriginalFilename], [BlobKey], [FileSizeBytes], [MimeType], [DocumentType], [Title],
                     [UploadedByUserId], [UploadedByEmail], [UploadedAt])
                 OUTPUT INSERTED.[PublicId]
                 VALUES (:requestId, :filename, :blobKey, :size, :mime, :documentType, :title, :userId, :email, :at)',
                [
                    'requestId' => $request['id'],
                    'filename' => $new->filename,
                    'blobKey' => $new->blobKey,
                    'size' => $new->sizeBytes,
                    'mime' => $new->mimeType,
                    'documentType' => $new->documentType,
                    'title' => $new->title,
                    'userId' => $new->uploadedBy->userId,
                    'email' => $new->uploadedBy->email,
                    'at' => $now,
                ],
            )[0] ?? throw new RuntimeException('NPR attachment insert returned no row.');
            $this->activity($request, $new->uploadedBy, $actorRole, 'attachment_added', 'Attachment added: ' . $new->filename, $now);

            return strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null));
        });

        return $publicId === null ? null : $this->find($id, $publicId);
    }

    public function list(string $requestPublicId): array
    {
        $id = self::guid($requestPublicId);
        if ($id === null) {
            return [];
        }

        return array_map(
            self::attachment(...),
            $this->connection->fetchAll(
                self::SELECT . ' WHERE r.[PublicId] = :id AND a.[IsDeleted] = 0 ORDER BY a.[UploadedAt] ASC, a.[AttachmentId] ASC',
                ['id' => $id],
            ),
        );
    }

    public function allBlobKeys(string $requestPublicId): array
    {
        $request = self::guid($requestPublicId);
        if ($request === null) {
            return [];
        }
        $rows = $this->connection->fetchAll(
            'SELECT a.[BlobKey] FROM [npr].[Attachments] a
             INNER JOIN [npr].[Requests] r ON r.[RequestId] = a.[RequestId]
             WHERE r.[PublicId] = :request',
            ['request' => $request],
        );

        return array_map(static fn (array $row): string => AzureSqlValueMapper::stringValue($row["BlobKey"] ?? null), $rows);
    }

    public function find(string $requestPublicId, string $attachmentPublicId): ?NprAttachment
    {
        $request = self::guid($requestPublicId);
        $file = self::guid($attachmentPublicId);
        if ($request === null || $file === null) {
            return null;
        }
        $row = $this->connection->fetchAll(
            self::SELECT . ' WHERE r.[PublicId] = :request AND a.[PublicId] = :file AND a.[IsDeleted] = 0',
            ['request' => $request, 'file' => $file],
        )[0] ?? null;

        return $row === null ? null : self::attachment($row);
    }

    public function softDelete(string $requestPublicId, string $attachmentPublicId, NprPerson $by, string $actorRole): ?NprAttachment
    {
        $request = self::guid($requestPublicId);
        $file = self::guid($attachmentPublicId);
        if ($request === null || $file === null) {
            return null;
        }
        $now = AzureSqlValueMapper::formatDateTime($this->clock->now());

        return $this->runInTransaction($this->connection, function () use ($request, $file, $by, $actorRole, $now): ?NprAttachment {
            $locked = $this->lockRequest($request);
            $existing = $this->find($request, $file);
            if ($locked === null || $existing === null) {
                return null;
            }
            $this->connection->execute(
                'UPDATE [npr].[Attachments]
                 SET [IsDeleted] = 1, [DeletedAt] = :at, [DeletedByUserId] = :userId, [DeletedByEmail] = :email
                 WHERE [PublicId] = :file AND [IsDeleted] = 0',
                ['at' => $now, 'userId' => $by->userId, 'email' => $by->email, 'file' => $file],
            );
            $this->activity($locked, $by, $actorRole, 'attachment_removed', 'Attachment removed: ' . $existing->filename, $now);

            return $existing;
        });
    }

    /** @return array{id: int, revision: int, status: string}|null */
    private function lockRequest(string $publicId): ?array
    {
        $row = $this->connection->fetchAll(
            'SELECT [RequestId], [CurrentRevision], [Status] FROM [npr].[Requests] WITH (UPDLOCK, ROWLOCK) WHERE [PublicId] = :id',
            ['id' => $publicId],
        )[0] ?? null;

        return $row === null ? null : [
            'id' => AzureSqlValueMapper::intValue($row['RequestId'] ?? null),
            'revision' => AzureSqlValueMapper::intValue($row['CurrentRevision'] ?? null),
            'status' => AzureSqlValueMapper::stringValue($row['Status'] ?? null),
        ];
    }

    /** @param array{id: int, revision: int, status: string} $request */
    private function activity(array $request, NprPerson $actor, string $role, string $action, string $summary, string $now): void
    {
        $this->connection->execute(
            'INSERT INTO [npr].[Activity]
                ([RequestId], [RevisionNumber], [ActorUserId], [ActorEmail], [ActorName], [ActorRole], [Action],
                 [BeforeStatus], [AfterStatus], [ChangeSummary], [CreatedAt])
             VALUES (:requestId, :revision, :userId, :email, :name, :role, :action, :before, :after, :summary, :at)',
            [
                'requestId' => $request['id'],
                'revision' => $request['revision'],
                'userId' => $actor->userId,
                'email' => $actor->email,
                'name' => $actor->name,
                'role' => $role,
                'action' => $action,
                'before' => $request['status'],
                'after' => $request['status'],
                'summary' => $summary,
                'at' => $now,
            ],
        );
    }

    private static function guid(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match(self::GUID, $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $row */
    private static function attachment(array $row): NprAttachment
    {
        $email = AzureSqlValueMapper::stringValue($row['UploadedByEmail'] ?? null);
        $name = AzureSqlValueMapper::nullableString($row['UploadedByName'] ?? null);

        return new NprAttachment(
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            strtolower(AzureSqlValueMapper::stringValue($row['RequestPublicId'] ?? null)),
            AzureSqlValueMapper::stringValue($row['OriginalFilename'] ?? null),
            AzureSqlValueMapper::stringValue($row['BlobKey'] ?? null),
            AzureSqlValueMapper::intValue($row['FileSizeBytes'] ?? null),
            AzureSqlValueMapper::stringValue($row['MimeType'] ?? null),
            AzureSqlValueMapper::stringValue($row['DocumentType'] ?? null),
            AzureSqlValueMapper::stringValue($row['Title'] ?? null),
            new NprPerson(AzureSqlValueMapper::intValue($row['UploadedByUserId'] ?? null), $email, $name !== null && $name !== '' ? $name : $email),
            new DateTimeImmutable(AzureSqlValueMapper::stringValue($row['UploadedAt'] ?? null), new DateTimeZone('UTC')),
        );
    }
}
