<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

use DateTimeImmutable;

/**
 * One row of `finance.attachments`. Unattached (no record) from the upload until an
 * app attaches it to the record it belongs to. `blobPath` is generated; `originalName`
 * is what the sender called the file, kept as data.
 */
final readonly class FinanceAttachment
{
    public function __construct(
        public string $id,
        public string $application,
        public ?string $recordType,
        public ?string $recordId,
        public string $blobPath,
        public string $originalName,
        public string $contentType,
        public int $byteSize,
        public string $sha256,
        public ?int $uploadedByUserId,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $attachedAt,
    ) {
    }

    public function isAttached(): bool
    {
        return $this->recordId !== null;
    }

    public function withRecord(string $recordType, string $recordId, DateTimeImmutable $at): self
    {
        return new self(
            $this->id, $this->application, $recordType, $recordId, $this->blobPath, $this->originalName,
            $this->contentType, $this->byteSize, $this->sha256, $this->uploadedByUserId, $this->createdAt, $at,
        );
    }

    /**
     * The name for a `Content-Disposition` header: the last path segment only, with
     * anything a header or a file system dislikes replaced, so a name like
     * `..\..\x.pdf` downloads as `x.pdf`.
     */
    public function downloadName(): string
    {
        $parts = preg_split('~[\\\\/]~', $this->originalName) ?: [];
        $last = trim((string) end($parts));
        $last = preg_replace('/[\x00-\x1f\x7f"<>:*?|]/', '_', $last) ?? '';

        return $last === '' || $last === '.' || $last === '..' ? 'file' : $last;
    }
}
