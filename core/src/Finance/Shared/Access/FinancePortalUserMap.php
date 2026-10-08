<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

/**
 * `finance.portal_user_map`: how a data migration turns a portal user id found in a
 * finance app's rows into the platform user the row now belongs to.
 */
interface FinancePortalUserMap
{
    /** Null when that portal user was never matched to a platform user. */
    public function platformUserIdFor(string $portalUserId): ?int;

    /** @return array<string, FinancePortalUserMapping> keyed by portal user id */
    public function all(): array;

    /**
     * Adds a mapping. False, nothing changed, when the portal user already has one or
     * the platform user is already mapped from another portal user.
     */
    public function add(FinancePortalUserMapping $mapping): bool;
}
