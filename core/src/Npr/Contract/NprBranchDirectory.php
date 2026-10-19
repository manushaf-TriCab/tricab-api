<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

/** Which branch a requester belongs to. Identity holds no branch, so this has its own source. */
interface NprBranchDirectory
{
    public const UNASSIGNED = 'unassigned';

    /** The branch for this email address, or {@see self::UNASSIGNED}. */
    public function branchForEmail(string $email): string;
}
