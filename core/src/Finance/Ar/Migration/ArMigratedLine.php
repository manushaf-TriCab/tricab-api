<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use DateTimeImmutable;

/** One `ar_remittance_lines` row to write. `lineNo` is the line's place among the attachment's lines in the source's row order, from 1. */
final readonly class ArMigratedLine
{
    public function __construct(
        public string $id,
        public string $attachmentId,
        public string $recordId,
        public int $lineNo,
        public ?string $invoiceNumber,
        public ?string $invoiceDate,
        public ?string $grossAmount,
        public ?string $discountAmount,
        public ?string $paidAmount,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
