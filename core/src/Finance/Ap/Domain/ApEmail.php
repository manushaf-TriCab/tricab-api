<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.invoice_emails`: a message the scan has seen (the source's `emails` row). Only the preview of the body
 * is ever stored (question Q18); the body itself is read from the mailbox each time it is looked at.
 *
 * - A text column that is `NOT NULL DEFAULT ''` in the table is a string here, `''` for none.
 * - `receivedAt` and every timestamp are UTC; `receivedAt` is null for a message Graph gave no time for.
 * - `classification` is `Invoice` or `Other` (the table also allows `Error`, which nothing writes); `reviewStatus` is
 *   `Unverified`, `Verified` or `Needs review`.
 * - `deletedAt` set means the email is in the Bin.
 */
final readonly class ApEmail
{
    public const INVOICE = 'Invoice';
    public const OTHER = 'Other';
    public const ERROR = 'Error';
    /** What a person may set. */
    public const SETTABLE_CLASSIFICATIONS = [self::INVOICE, self::OTHER];
    public const CLASSIFICATIONS = [self::INVOICE, self::OTHER, self::ERROR];

    public const UNVERIFIED = 'Unverified';
    public const VERIFIED = 'Verified';
    public const NEEDS_REVIEW = 'Needs review';
    public const REVIEW_STATUSES = [self::UNVERIFIED, self::VERIFIED, self::NEEDS_REVIEW];

    /** The reason a new email carries until the scan has classified it. */
    public const PENDING_REASON = 'Pending classification';
    /** The reason a person's reclassification carries when they give none. */
    public const MANUAL_REASON = 'Manually classified';
    /** The scan's reasons are cut to this. */
    public const REASON_LIMIT = 1000;

    /** The widths of the text columns that have one (the table's `NVARCHAR(n)`). */
    public const TEXT_LIMITS = [
        'graph_message_id' => 200, 'mailbox' => 320, 'sender_name' => 400, 'sender_email' => 320, 'subject' => 400,
        'body_preview' => 1000, 'web_link' => 2000, 'classification_reason' => self::REASON_LIMIT,
    ];

    /**
     * The columns {@see \TriCab\Core\Finance\Ap\Contract\ApEmailStore::update()} may write: what the scan refreshes and
     * decides, and what a reclassification sets. Not the id, the mailbox, the Graph id, the Bin columns (they have their own
     * calls) or the creation time.
     */
    public const UPDATABLE = [
        'received_at', 'sender_name', 'sender_email', 'subject', 'body_preview', 'web_link', 'has_attachments', 'classification',
        'classification_reason', 'classification_confidence', 'review_status', 'proposed_response', 'scan_run_id',
    ];

    public function __construct(
        public string $id,
        public string $graphMessageId,
        public string $mailbox,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $receivedAt = null,
        public string $senderName = '',
        public string $senderEmail = '',
        public string $subject = '',
        public string $bodyPreview = '',
        public string $webLink = '',
        public bool $hasAttachments = false,
        public string $classification = self::OTHER,
        public ?string $classificationReason = null,
        public ?float $classificationConfidence = null,
        public string $reviewStatus = self::UNVERIFIED,
        public ?string $proposedResponse = null,
        public ?string $scanRunId = null,
        public ?DateTimeImmutable $deletedAt = null,
        public ?int $deletedByUserId = null,
        public string $deletedBy = '',
    ) {
    }

    /** A copy with some properties replaced, by property name (`classification`, `reviewStatus`). */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * A copy with some columns replaced, by column name (`classification_reason`), as a store writes them.
     *
     * @param array<string, mixed> $columns
     */
    public function withColumns(array $columns): self
    {
        $changes = [];
        foreach ($columns as $column => $value) {
            $changes[lcfirst(str_replace('_', '', ucwords($column, '_')))] = $value;
        }

        return $this->with(...$changes);
    }

    public function isBinned(): bool
    {
        return $this->deletedAt !== null;
    }
}
