<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Attachments;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * `finance.attachments` (`create-finance-attachments-schema.sql`) and the bytes in the
 * private `BLOB_CONTAINER_FINANCE` container through the platform's blob store: in
 * memory locally (`BLOB_AUTH_MODE=memory`), Azure otherwise, like onboarding's and
 * RFQ's documents. The container must never be public.
 *
 * Order matters. A put writes the bytes before the row, so a row always names bytes
 * that exist and a failed row leaves only an unreferenced blob. Deleting a record's
 * files removes each file's bytes before its row, so a failure leaves a row that can
 * be deleted again rather than bytes nothing points to. Sweeping unattached uploads
 * removes the row first, in one statement that only takes a row still unattached, so
 * a file attached at that moment is never swept; its bytes follow.
 */
final class AzureSqlFinanceAttachmentStore implements FinanceAttachmentStore
{
    private const COLUMNS = '[id], [application_key], [record_type], [record_id], [blob_path], [original_name], [content_type],
        [byte_size], [sha256], [uploaded_by_user_id], [created_at], [attached_at]';

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly ProductBlobStore $blobs,
        private readonly string $container,
    ) {
    }

    public function put(FinanceAttachment $attachment, string $bytes): void
    {
        $this->blobs->putObject($this->container, $attachment->blobPath, $attachment->contentType, $bytes);
        $this->connection->execute(
            'INSERT INTO [finance].[attachments] (' . self::COLUMNS . ')
             VALUES (:id, :app, :record_type, :record_id, :blob_path, :name, :type, :size, :sha, :uploader, :created, :attached)',
            [
                'id' => $attachment->id,
                'app' => $attachment->application,
                'record_type' => $attachment->recordType,
                'record_id' => $attachment->recordId,
                'blob_path' => $attachment->blobPath,
                'name' => $attachment->originalName,
                'type' => $attachment->contentType,
                'size' => $attachment->byteSize,
                'sha' => $attachment->sha256,
                'uploader' => $attachment->uploadedByUserId,
                'created' => AzureSqlValueMapper::formatDateTime($attachment->createdAt),
                'attached' => $attachment->attachedAt === null ? null : AzureSqlValueMapper::formatDateTime($attachment->attachedAt),
            ],
        );
    }

    public function find(string $application, string $id): ?FinanceAttachment
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[attachments] WHERE [id] = :id AND [application_key] = :app',
            ['id' => $id, 'app' => $application],
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function bytes(FinanceAttachment $attachment): ?string
    {
        return $this->blobs->getObjectBinary($this->container, $attachment->blobPath);
    }

    public function attach(string $application, string $id, string $recordType, string $recordId, DateTimeImmutable $at): bool
    {
        // One statement; OUTPUT says whether it took the row. Only an unattached file is taken.
        return $this->connection->fetchAll(
            'UPDATE [finance].[attachments]
                SET [record_type] = :record_type, [record_id] = :record_id, [attached_at] = :at
                OUTPUT inserted.[id]
              WHERE [id] = :id AND [application_key] = :app AND [record_id] IS NULL',
            ['record_type' => $recordType, 'record_id' => $recordId, 'at' => AzureSqlValueMapper::formatDateTime($at), 'id' => $id, 'app' => $application],
        ) !== [];
    }

    public function forRecord(string $application, string $recordType, string $recordId): array
    {
        return array_map(self::hydrate(...), $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[attachments]
              WHERE [application_key] = :app AND [record_type] = :record_type AND [record_id] = :record_id
              ORDER BY [created_at], [id]',
            ['app' => $application, 'record_type' => $recordType, 'record_id' => $recordId],
        ));
    }

    public function countsByRecordType(string $application, string $recordType): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [record_id], COUNT(*) AS [n] FROM [finance].[attachments]
              WHERE [application_key] = :app AND [record_type] = :record_type AND [record_id] IS NOT NULL
              GROUP BY [record_id]',
            ['app' => $application, 'record_type' => $recordType],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[AzureSqlValueMapper::stringValue($row['record_id'])] = AzureSqlValueMapper::intValue($row['n']);
        }

        return $counts;
    }

    public function deleteForRecord(string $application, string $recordType, string $recordId): int
    {
        $rows = $this->forRecord($application, $recordType, $recordId);
        foreach ($rows as $row) {
            $this->removeBytesThenRow($row);
        }

        return count($rows);
    }

    public function delete(string $application, string $id): bool
    {
        $row = $this->find($application, $id);
        if ($row === null) {
            return false;
        }
        $this->removeBytesThenRow($row);

        return true;
    }

    public function deleteUnattachedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        $candidates = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, $limit) . ') [id] FROM [finance].[attachments]
              WHERE [record_id] IS NULL AND [created_at] < :cutoff ORDER BY [created_at], [id]',
            ['cutoff' => AzureSqlValueMapper::formatDateTime($cutoff)],
        );
        $deleted = 0;
        foreach ($candidates as $candidate) {
            $taken = $this->connection->fetchAll(
                'DELETE FROM [finance].[attachments] OUTPUT deleted.[blob_path] WHERE [id] = :id AND [record_id] IS NULL',
                ['id' => AzureSqlValueMapper::stringValue($candidate['id'])],
            );
            if ($taken === []) {
                continue;
            }
            $this->blobs->deleteObject($this->container, AzureSqlValueMapper::stringValue($taken[0]['blob_path']));
            ++$deleted;
        }

        return $deleted;
    }

    private function removeBytesThenRow(FinanceAttachment $row): void
    {
        $this->blobs->deleteObject($this->container, $row->blobPath);
        $this->connection->execute('DELETE FROM [finance].[attachments] WHERE [id] = :id', ['id' => $row->id]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): FinanceAttachment
    {
        return new FinanceAttachment(
            AzureSqlValueMapper::stringValue($row['id']),
            AzureSqlValueMapper::stringValue($row['application_key']),
            AzureSqlValueMapper::nullableString($row['record_type']),
            AzureSqlValueMapper::nullableString($row['record_id']),
            AzureSqlValueMapper::stringValue($row['blob_path']),
            AzureSqlValueMapper::stringValue($row['original_name']),
            AzureSqlValueMapper::stringValue($row['content_type']),
            AzureSqlValueMapper::intValue($row['byte_size']),
            AzureSqlValueMapper::stringValue($row['sha256']),
            AzureSqlValueMapper::nullableInt($row['uploaded_by_user_id']),
            AzureSqlValueMapper::requireDateTime($row['created_at'], 'created_at'),
            AzureSqlValueMapper::nullableDateTime($row['attached_at']),
        );
    }
}
