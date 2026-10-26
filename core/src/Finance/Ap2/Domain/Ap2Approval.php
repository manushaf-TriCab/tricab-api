<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.ap2_approvals`: a request that one person approve one item (an invoice, an email draft or a
 * payment proposal). `snapshot` is the hash of what the approver was shown; it is compared again at decision time.
 * `status` is the STORED status; {@see self::effectiveStatus()} adds expiry, which is computed on read.
 */
final readonly class Ap2Approval
{
    public const KINDS = ['Invoice', 'Email', 'Payment'];

    public const STATUSES = ['Pending', 'Approved', 'Rejected', 'Superseded', 'Expired'];

    public function __construct(
        public string $id,
        public string $kind,
        public Ap2Person $requester,
        public Ap2Person $approver,
        public DateTimeImmutable $requestedAt,
        public DateTimeImmutable $updatedAt,
        public ?string $invoiceId = null,
        public ?string $mailId = null,
        public ?string $paymentId = null,
        public ?string $supplierId = null,
        public ?string $retryOf = null,
        public string $subject = '',
        public string $summary = '',
        public ?string $snapshot = null,
        public string $status = 'Pending',
        public ?DateTimeImmutable $decidedAt = null,
        public string $comment = '',
        public ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** Pending past its expiry reads as Expired; nothing is written when that happens. */
    public function effectiveStatus(DateTimeImmutable $now): string
    {
        if ($this->status === 'Pending' && $this->expiresAt !== null && $this->expiresAt <= $now) {
            return 'Expired';
        }

        return $this->status;
    }

    /** The id of the thing this approval is about, by kind. */
    public function itemId(): string
    {
        return match ($this->kind) {
            'Invoice' => $this->invoiceId ?? '',
            'Email' => $this->mailId ?? '',
            default => $this->paymentId ?? '',
        };
    }
}
