<?php

declare(strict_types=1);

namespace TriCab\Core\Npr;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * The only way CableFit reads NPR data. It never touches NPR tables.
 *
 * The implementation lands in a later PR (plan NPR-TSD-MIGRATION-PLAN, N1.5);
 * nothing implements or registers this yet.
 */
interface NprRequestReader
{
    /**
     * The request's current revision, or null when it does not exist or the
     * user may not read it. The two cases are not distinguishable.
     */
    public function findForTsd(AuthenticatedUser $user, string $requestPublicId): ?NprRequestSnapshot;

    /**
     * {@see findForTsd()} for several requests at once, keyed by public id.
     * Requests that are missing or not readable are left out.
     *
     * @param list<string> $requestPublicIds
     *
     * @return array<string, NprRequestSnapshot>
     */
    public function findManyForTsd(AuthenticatedUser $user, array $requestPublicIds): array;
}
