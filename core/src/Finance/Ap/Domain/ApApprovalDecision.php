<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.invoice_approval_decisions`: who decided a batch and how. `decision` is the batch's final status,
 * `Approved` or `Rejected` (not the request word `approve` / `reject`). One decision completes a batch, so a batch has at most one.
 */
final readonly class ApApprovalDecision
{
    public function __construct(
        public string $id,
        public string $batchId,
        public ?int $approverUserId,
        public string $approverName,
        public string $approverEmail,
        public string $decision,
        public string $comment,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
