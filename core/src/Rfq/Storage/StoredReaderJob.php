<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;

/**
 * One `rfq_reader_jobs` row, as stored. `status` is the source's value
 * (`queued`, `running`, `done`, `failed`, `superseded`, `dismissed`);
 * {@see readerStatus()} is the platform's reading of it.
 *
 * `documentId` is set for the platform's own jobs, whose `spool_path` is
 * `rfq-document:<id>`; a job the source queued holds a filesystem path there
 * and has none.
 */
final class StoredReaderJob
{
    public const DOCUMENT_PREFIX = 'rfq-document:';

    /** A job that records an email follow-up's proposal (RFQ mail M6.2); its `spool_path` names the message. */
    public const EMAIL_PREFIX = 'mail-followup:';

    /**
     * @param array<string, mixed>|null $proposal
     */
    public function __construct(
        public readonly string $id,
        public readonly string $draftId,
        public readonly string $fileName,
        public readonly ?string $contentType,
        public readonly string $status,
        public readonly int $attempts,
        public readonly ?int $baseRevision,
        public readonly ?string $note,
        public readonly ?array $proposal,
        public readonly ?string $documentId,
        public readonly string $updatedAt,
        /** The mail message id of an email follow-up's read; null for a file's. */
        public readonly ?string $emailMessageId = null,
    ) {
    }

    public static function spoolPath(string $documentId): string
    {
        return self::DOCUMENT_PREFIX . $documentId;
    }

    public static function documentIdFrom(?string $spoolPath): ?string
    {
        if ($spoolPath === null || !str_starts_with($spoolPath, self::DOCUMENT_PREFIX)) {
            return null;
        }
        $id = substr($spoolPath, strlen(self::DOCUMENT_PREFIX));

        return StoreFormat::isId($id) ? $id : null;
    }

    public static function emailMessageIdFrom(?string $spoolPath): ?string
    {
        return $spoolPath !== null && str_starts_with($spoolPath, self::EMAIL_PREFIX) ? substr($spoolPath, strlen(self::EMAIL_PREFIX)) : null;
    }

    public function readerStatus(): ReaderJobStatus
    {
        return StoreProjection::readerStatus($this->status, $this->proposal !== null && $this->proposal !== []);
    }
}
