<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.invoice_approval_batches`: a request to sign off a set of invoices (the source's `approval_batches` row).
 *
 * - `status` is `Pending` from creation; the first decision makes it `Approved` or `Rejected` and it never changes again.
 * - The people are recorded by platform user id AND by the name and address they had at the time (question Q10), so a batch
 *   reads the same whatever happens to the account. The deciding person's address is on the decision row.
 * - `note` is `''` for none; `decisionComment` is null until a decision is made, `''` for a decision with no comment.
 */
final readonly class ApApprovalBatch
{
    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED];

    public function __construct(
        public string $id,
        public ?int $preparedByUserId,
        public string $preparedByName,
        public string $preparedByEmail,
        public string $status,
        public string $note,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?int $decidedByUserId = null,
        public string $decidedByName = '',
        public ?DateTimeImmutable $decidedAt = null,
        public ?string $decisionComment = null,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
