<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

use DateTimeImmutable;

/**
 * One row of `ar_credit_note_requests`. The token itself is never held: only its SHA-256 (decision Q6). The page and
 * the mail get the link once, when it is made.
 */
final readonly class ArCreditNote
{
    public const FORM_SENT = 'form_sent';
    public const AWAITING_SALES_RESPONSE = 'awaiting_sales_response';
    public const SUBMITTED = 'submitted';
    public const UNDER_REVIEW = 'under_review';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const COMPLETED = 'completed';

    public const STATUSES = [
        self::FORM_SENT, self::AWAITING_SALES_RESPONSE, self::SUBMITTED, self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::COMPLETED,
    ];

    /** A request the rep may still complete: the form link works. */
    public const OPEN = [self::FORM_SENT, self::AWAITING_SALES_RESPONSE];

    /** Moving to one of these for the first time stamps `reviewed_at` (decision D58). */
    public const REVIEWED = [self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::COMPLETED];

    public function __construct(
        public string $id,
        public ?string $emailRecordId,
        public string $customerName,
        public string $customerEmail,
        public string $salesRepName,
        public string $salesRepEmail,
        public string $invoiceNumber,
        public float $creditAmount,
        public string $reason,
        public string $originalRequest,
        public string $tokenHash,
        public DateTimeImmutable $tokenExpiresAt,
        public string $status,
        public ?DateTimeImmutable $formSentDate,
        public ?DateTimeImmutable $submittedDate,
        public ?DateTimeImmutable $reviewedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
