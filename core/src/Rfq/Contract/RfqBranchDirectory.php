<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

/**
 * Which branch a platform user belongs to: the default for the queue's branch
 * filter, and the branch a new RFQ is stamped with. Never a limit on what the
 * person sees (owner decision, 28 Sep 2026).
 *
 * **There is no implementation that answers yet.** Platform identity has no
 * branch, office or territory (`identity.Users`), and where the answer should
 * come from — a new identity attribute, the legacy 4J/AD mapping the source
 * used, or a regions table — is owner question Q7. Until then
 * `UnconfiguredRfqBranchDirectory` answers null for everybody: no default
 * filter, and new RFQs are stamped with no branch.
 *
 * The source's fallback of reusing the last branch it had seen for a person
 * is not carried: a stale branch would stamp new work wrongly.
 */
interface RfqBranchDirectory
{
    public function branchFor(int $userId): ?string;

    /** `RfqAccessSummary::BRANCH_SOURCE_*`, for the access summary. */
    public function source(): string;
}
