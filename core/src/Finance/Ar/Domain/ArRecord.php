<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

use DateTimeImmutable;

/**
 * One row of `ar_email_records`: a message in the mailbox, with the AI's reading of it and what
 * the team has done about it. The stored `followUpStatus` is `needs_review`, `in_progress` or
 * `completed`; "overdue" is derived on read ({@see effectiveStatus}) and never stored (question Q8).
 */
final readonly class ArRecord
{
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_OVERDUE = 'overdue';

    /** What a person may set (`SETTABLE_FOLLOW_UP_STATUSES`): never `overdue`. */
    public const SETTABLE_FOLLOW_UP_STATUSES = [self::STATUS_NEEDS_REVIEW, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED];

    /** `status`: `new`, `reviewed`, `sent` and, from the register's "skip", `skipped` (T5.0 finding 2). */
    public const STATUSES = ['new', 'reviewed', 'sent', 'skipped'];

    /**
     * @param string|null $dueDate `yyyy-MM-dd` or null for none
     * @param string|null $body null when the row was read without its body (a list); the source cut it to 20,000 characters on ingest
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $emailDate,
        public string $customerName,
        public string $senderEmail,
        public string $senderName,
        public string $subject,
        public ?string $body,
        public string $aiSummary,
        public string $followUpCategory,
        public ?int $assignedUserId,
        public string $assignedName,
        public string $proposedResponseType,
        public string $proposedResponse,
        public string $followUpStatus,
        public ?string $dueDate,
        public string $finalOutcome,
        public ?string $sourceMessageId,
        public bool $messageUnreachable,
        public string $mailbox,
        public ?float $classificationConfidence,
        public string $status,
        public string $assignedSalesRepEmail,
        public ?string $creditNoteRequestId,
        public ?DateTimeImmutable $reminderSentAt,
        public ?DateTimeImmutable $deletedAt,
        public ?int $deletedByUserId,
        public string $deletedBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /** A copy with some properties replaced (by property name). */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * The status the register shows: an open record (`needs_review` or `in_progress`) whose due date
     * is before `$today` (a calendar date in the Tri-AR zone) is `overdue`; a `completed` one never
     * is. The source wrote this into the column before every read (`refresh_overdue_statuses`).
     */
    public function effectiveStatus(string $today): string
    {
        return self::derive($this->followUpStatus, $this->dueDate, $today);
    }

    public static function derive(string $stored, ?string $dueDate, string $today): string
    {
        if (($stored === self::STATUS_NEEDS_REVIEW || $stored === self::STATUS_IN_PROGRESS) && $dueDate !== null && $dueDate < $today) {
            return self::STATUS_OVERDUE;
        }

        return $stored;
    }
}
