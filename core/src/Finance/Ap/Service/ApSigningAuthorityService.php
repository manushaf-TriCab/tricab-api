<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Contract\ApSigningLimitStore;
use TriCab\Core\Finance\Ap\Domain\ApSigningLimit;

/**
 * `GET /signing-authority` (source route 57): who holds signing authority, from the "Limits by Position"
 * sheet. A suggestion list for the approval dialog, not a permission check (question Q12). A vacant
 * position (no address) is left out, as is an inactive one: an empty seat has nobody to email. Every
 * limit is returned, not one chosen column.
 */
final class ApSigningAuthorityService
{
    public function __construct(private readonly ApSigningLimitStore $store)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $rows = array_filter($this->store->all(), static fn (ApSigningLimit $r): bool => $r->email !== '' && $r->active);

        return array_values(array_map(static fn (ApSigningLimit $r): array => ApPresenter::signingLimit($r), $rows));
    }
}
