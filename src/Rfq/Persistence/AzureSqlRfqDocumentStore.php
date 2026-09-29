<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Persistence;

use Closure;
use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Rfq\Application\DTO\SourceDocument;
use TriCab\Core\Rfq\Contract\RfqDocumentStore;
use TriCab\Core\Rfq\Storage\StoreFormat;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Shared\Time\Clock;

/**
 * RFQ documents (plan 2.5): the bytes in the private `BLOB_CONTAINER_RFQ`
 * container through the platform's blob store — in memory locally
 * (`BLOB_AUTH_MODE=memory`), Azure otherwise, like onboarding's documents —
 * and a `[rfq].[rfq_documents]` row each (`create-rfq-documents-schema.sql`).
 *
 * The blob key is `drafts/<draft id>/<document id>`, generated here: the
 * uploaded file name is display metadata and is never part of a key or path.
 * The bytes are written before the row, so a row always names bytes that
 * exist; a failed row leaves an unreferenced blob, which is the cheaper
 * orphan to sweep.
 */
final class AzureSqlRfqDocumentStore implements RfqDocumentStore
{
    /** @var Closure(): string */
    private readonly Closure $ids;

    /**
     * @param (callable(): string)|null $ids new 32-hex ids
     */
    public function __construct(
        private readonly SqlConnection $connection,
        private readonly ProductBlobStore $blobs,
        private readonly string $container,
        private readonly Clock $clock,
        ?callable $ids = null,
    ) {
        $this->ids = Closure::fromCallable($ids ?? StoreFormat::newId(...));
    }

    public function store(string $draftId, string $fileName, string $contentType, string $bytes, string $uploaderEmail): SourceDocument
    {
        $ids = $this->ids;
        $id = $ids();
        $path = "drafts/{$draftId}/{$id}";
        $type = $contentType !== '' ? $contentType : 'application/octet-stream';
        $this->blobs->putObject($this->container, $path, $type, $bytes);
        $now = $this->clock->now();
        $kind = self::kind($fileName, $contentType);
        $sha = hash('sha256', $bytes);
        $this->connection->execute(
            'INSERT INTO [rfq].[rfq_documents]
                ([id], [draft_id], [created_at], [uploader_email], [file_name], [content_type], [kind], [byte_size], [sha256], [blob_container], [blob_path])
             VALUES (:id, :draft_id, :created_at, :uploader_email, :file_name, :content_type, :kind, :byte_size, :sha256, :blob_container, :blob_path)',
            [
                'id' => $id,
                'draft_id' => $draftId,
                'created_at' => StoreFormat::isoTime($now),
                'uploader_email' => $uploaderEmail !== '' ? $uploaderEmail : null,
                'file_name' => $fileName,
                'content_type' => $contentType !== '' ? $contentType : null,
                'kind' => $kind,
                'byte_size' => strlen($bytes),
                'sha256' => $sha,
                'blob_container' => $this->container,
                'blob_path' => $path,
            ],
        );

        return new SourceDocument(StoreProjection::publicId($id), $kind, $fileName, $contentType !== '' ? $contentType : null, strlen($bytes), $sha, $now);
    }

    public function bytes(string $draftId, string $documentId): ?string
    {
        $row = $this->row($draftId, $documentId);
        if ($row === null || AzureSqlValueMapper::nullableString($row['deleted_at']) !== null) {
            return null;
        }

        return $this->blobs->getObjectBinary(
            AzureSqlValueMapper::stringValue($row['blob_container']),
            AzureSqlValueMapper::stringValue($row['blob_path']),
        );
    }

    public function find(string $draftId, string $documentId): ?SourceDocument
    {
        $row = $this->row($draftId, $documentId);

        return $row === null ? null : self::document($row);
    }

    public function forDraft(string $draftId): array
    {
        return array_map(self::document(...), $this->connection->fetchAll(
            'SELECT * FROM [rfq].[rfq_documents] WHERE [draft_id] = :draft ORDER BY [created_at], [id]',
            ['draft' => $draftId],
        ));
    }

    /** @return array<string, mixed>|null */
    private function row(string $draftId, string $documentId): ?array
    {
        if (!StoreFormat::isId($draftId) || !StoreFormat::isId($documentId)) {
            return null;
        }

        return $this->connection->fetchAll(
            'SELECT * FROM [rfq].[rfq_documents] WHERE [id] = :id AND [draft_id] = :draft',
            ['id' => $documentId, 'draft' => $draftId],
        )[0] ?? null;
    }

    /** @param array<string, mixed> $row */
    private static function document(array $row): SourceDocument
    {
        return new SourceDocument(
            StoreProjection::publicId(AzureSqlValueMapper::stringValue($row['id'])),
            AzureSqlValueMapper::stringValue($row['kind']),
            AzureSqlValueMapper::stringValue($row['file_name']),
            AzureSqlValueMapper::nullableString($row['content_type']),
            AzureSqlValueMapper::intValue($row['byte_size']),
            AzureSqlValueMapper::stringValue($row['sha256']),
            StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['created_at'])) ?? new DateTimeImmutable('@0'),
        );
    }

    private static function kind(string $fileName, string $contentType): string
    {
        $suffix = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return str_starts_with(strtolower($contentType), 'image/') || in_array($suffix, ['png', 'jpg', 'jpeg', 'webp'], true)
            ? SourceDocument::KIND_IMAGE
            : SourceDocument::KIND_FILE;
    }
}
