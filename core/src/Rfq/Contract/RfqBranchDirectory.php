<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

/**
 * Which branch a platform user belongs to, for RFQ scope.
 *
 * **There is no implementation that answers yet.** Platform identity has no
 * branch, office or territory (`identity.Users`), and where the answer should
 * come from — a new identity attribute, the legacy 4J/AD mapping the source
 * used, or a regions table — is owner question Q7, which blocks plan 1.4.
 * Until then `UnconfiguredRfqBranchDirectory` answers null for everybody, and
 * `RfqAccessPolicy` narrows every branch-scoped actor to their own work.
 *
 * That is the safe direction to be wrong in. The source's other fallback —
 * reusing the last branch it had seen for a person — is not an option here:
 * a stale branch is a grant nobody reviewed.
 */
interface RfqBranchDirectory
{
    public function branchFor(int $userId): ?string;

    /** `RfqAccessSummary::BRANCH_SOURCE_*`, for the access summary. */
    public function source(): string;
}
