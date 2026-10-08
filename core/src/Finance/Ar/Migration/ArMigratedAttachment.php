<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use DateTimeImmutable;

/**
 * One `ar_email_attachments` row to write, with the file it points at. `fileId` is the `finance.attachments` id,
 * derived from the source row's id so a second run finds the same file. Amounts are decimal text ("1234.50").
 */
final readonly class ArMigratedAttachment
{
    public function __construct(
        public string $id,
        public string $recordId,
        public string $fileId,
        public bool $isRemittance,
        public ?string $payerName,
        public ?string $paymentDate,
        public ?string $paymentReference,
        public string $currency,
        public ?string $totalAmount,
        public ?float $extractionConfidence,
        public ?bool $reconciles,
        public string $extractionStatus,
        public ?string $extractionError,
        public ?string $notes,
        public ?DateTimeImmutable $extractedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $fileName,
        public string $contentType,
        public string $filePath,
        public int $fileSize,
    ) {
    }
}
