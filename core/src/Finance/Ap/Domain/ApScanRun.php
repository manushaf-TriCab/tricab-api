<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.invoice_scan_runs` (the source's `scan_runs`): a period scan and where it stands.
 *
 * - `Listing` (created, the mailboxes being listed) and `Running` (listed, the page calling `process`) hold the app's one
 *   claim; `Complete` and `Failed` release it.
 * - `mailboxes` are the addresses the run reads (`mailboxes_json`); the claim is for the app, not for one of them.
 * - `requestedBy` is the person's name at the time, so a later warning can say who scanned a period.
 */
final readonly class ApScanRun
{
    public const LISTING = 'Listing';
    public const RUNNING = 'Running';
    public const COMPLETE = 'Complete';
    public const FAILED = 'Failed';
    /** The statuses that hold the claim. */
    public const OPEN = [self::LISTING, self::RUNNING];

    /**
     * @param list<string> $mailboxes
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?int $requestedByUserId,
        public string $requestedBy,
        public ?string $fromDate,
        public ?string $toDate,
        public array $mailboxes,
        public string $status,
        public ?DateTimeImmutable $claimExpiresAt,
        public int $totalMessages = 0,
        public int $processedMessages = 0,
        public int $invoiceEmails = 0,
        public int $otherEmails = 0,
        public int $duplicateInvoices = 0,
        public int $errors = 0,
    ) {
    }

    /** A copy with some properties replaced, by property name. */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
