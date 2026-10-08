<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

use DateTimeImmutable;

/**
 * A file kept from a message, as `ar_email_attachments` holds it: the row's own id, the record, the file in
 * the finance file store, and where extraction stands. The name, type and size are carried so the in-memory
 * twin can show them; the SQL row keeps none of them (they live in `finance.attachments`, decision D41).
 */
final readonly class ArKeptAttachment
{
    public const PENDING = 'pending';
    public const EXTRACTED = 'extracted';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    public const STATUSES = [self::PENDING, self::EXTRACTED, self::FAILED, self::SKIPPED];

    public function __construct(
        public string $id,
        public string $recordId,
        public string $fileId,
        public string $fileName,
        public string $contentType,
        public int $sizeBytes,
        public string $extractionStatus,
        public DateTimeImmutable $at,
    ) {
    }
}
