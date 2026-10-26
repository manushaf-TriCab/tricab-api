<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.ap2_mail`: a message in the AP mailbox, copied in by the sync. The mailbox itself is never
 * touched (no move, flag or mark-read); `read`, `status` and the rest are Tri-AP2's own.
 */
final readonly class Ap2Mail
{
    public const CATEGORIES = ['Supplier invoice', 'Credit note', 'Supplier statement', 'Payment enquiry', 'Missing PO / receipt', 'Bank detail change', 'Other'];

    public const STATUSES = ['In progress', 'Done', 'On hold'];

    /**
     * @param list<string> $draftAttachmentIds ids of this mail's attachments chosen for the reply
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $receivedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $supplierId = null,
        public ?string $invoiceId = null,
        public string $mailbox = '',
        /** The Graph immutable id of the message in `mailbox`: the dedupe key, and what a threaded reply answers. */
        public ?string $graphMessageId = null,
        public string $subject = '',
        public string $bodyPreview = '',
        public string $body = '',
        public string $category = 'Other',
        public ?Ap2Person $owner = null,
        public string $status = 'In progress',
        public bool $read = false,
        public ?string $due = null,
        public string $from = '',
        public string $cc = '',
        public string $replyTo = '',
        public bool $recordHistory = true,
        public string $draft = '',
        public bool $draftReviewed = false,
        public array $draftAttachmentIds = [],
        public bool $sent = false,
        public ?DateTimeImmutable $sentAt = null,
        public ?string $approvalId = null,
        public ?string $aiSummary = null,
        public ?float $aiConfidence = null,
        /** The invoice fields read from an attachment at sync time: a suggestion only, never an invoice. */
        public ?Ap2MailExtraction $extraction = null,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
