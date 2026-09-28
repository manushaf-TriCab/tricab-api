<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * The facts about one RFQ that access decisions read, and nothing else.
 *
 * Kept apart from the RFQ's content so that the scope check can run in the
 * query that fetches a page of the queue, before any customer data is loaded.
 *
 *  - `ownerUserId` — who created it (source `created_by_email`). Null for an
 *    RFQ the mail worker could not attribute to a platform user.
 *  - `assigneeUserId` — who is working it (source `assignee_email`).
 *  - `branchCode` — the branch it belongs to. Null means *unattributed*.
 */
final class RfqOwnership
{
    public function __construct(
        public readonly ?int $ownerUserId,
        public readonly ?int $assigneeUserId,
        public readonly ?string $branchCode,
        public readonly bool $archived,
    ) {
    }

    public function isUnattributed(): bool
    {
        return $this->branchCode === null || trim($this->branchCode) === '';
    }

    public function isUnassigned(): bool
    {
        return $this->assigneeUserId === null;
    }
}
