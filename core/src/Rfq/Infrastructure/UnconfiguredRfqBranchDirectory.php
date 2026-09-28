<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Application\DTO\RfqAccessSummary;
use TriCab\Core\Rfq\Contract\RfqBranchDirectory;

/**
 * Nobody has a branch. The only honest answer until owner question Q7 is
 * settled; see {@see RfqBranchDirectory}.
 */
final class UnconfiguredRfqBranchDirectory implements RfqBranchDirectory
{
    public function branchFor(int $userId): ?string
    {
        unset($userId);

        return null;
    }

    public function source(): string
    {
        return RfqAccessSummary::BRANCH_SOURCE_UNAVAILABLE;
    }
}
