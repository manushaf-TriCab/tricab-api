<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One document an RFQ was read from: an upload, pasted text, an email body or
 * an attachment. Contract `rfq.document.v1`.
 *
 * `fileName` is **display metadata only** (plan 2.5). The bytes live in
 * private blob storage under a generated key that never leaves the server —
 * there is no storage key or path in {@see toArray()}, and the source's
 * `spool_path` has no equivalent. `sha256` identifies the content for
 * deduplication and for migration verification (plan 7.5).
 */
final class SourceDocument
{
    public const KIND_TEXT = 'text';
    public const KIND_FILE = 'file';
    public const KIND_IMAGE = 'image';
    public const KIND_EMAIL_BODY = 'email_body';

    public function __construct(
        public readonly string $publicId,
        public readonly string $kind,
        public readonly string $fileName,
        public readonly ?string $contentType,
        public readonly int $byteSize,
        public readonly string $sha256,
        public readonly DateTimeImmutable $receivedAtUtc,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'kind' => $this->kind,
            'fileName' => $this->fileName,
            'contentType' => $this->contentType,
            'byteSize' => $this->byteSize,
            'sha256' => $this->sha256,
            'receivedAt' => $this->receivedAtUtc->format(DateTimeInterface::ATOM),
        ];
    }
}
