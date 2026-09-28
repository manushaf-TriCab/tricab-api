<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * The facts about one RFQ that access decisions read, and nothing else.
 *
 * Kept apart from the RFQ's content, so that an access decision reads only
 * these facts.
 *
 *  - `ownerUserId` — who created it (source `created_by_email`). Null for an
 *    RFQ the mail worker could not attribute to a platform user.
 *  - `assigneeUserId` — who is working it (source `assignee_email`).
 *  - `branchCode` — the branch it belongs to, if any. A queue filter, never a
 *    limit on who sees it.
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

    public function isUnassigned(): bool
    {
        return $this->assigneeUserId === null;
    }
}
