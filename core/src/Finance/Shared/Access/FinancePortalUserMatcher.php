<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

use DateTimeImmutable;

/**
 * Matches portal users to platform users by email, and writes the matches. The match is
 * deliberately plain: a portal user's email against the addresses platform users hold. No
 * guessing from a name, and nobody is created. {@see plan()} writes nothing; {@see apply()}
 * writes only what the plan says to map, so running it twice is the same as running it once.
 */
final class FinancePortalUserMatcher
{
    public function __construct(
        private readonly FinancePlatformUserLookup $platformUsers,
        private readonly FinancePortalUserMap $map,
    ) {
    }

    /**
     * @param list<FinancePortalUser> $users
     * @param list<array{row: int, reason: string}> $rejected rows of the export that could not be read
     */
    public function plan(array $users, array $rejected = []): FinancePortalUserPlan
    {
        $found = $this->platformUsers->userIdsByEmail(array_values(array_unique(array_map(static fn (FinancePortalUser $u): string => $u->email, $users))));
        $existing = $this->map->all();

        /** @var array<int, string> $claimedBy platform user id => the portal user that has it, mapped or planned */
        $claimedBy = [];
        foreach ($existing as $mapping) {
            $claimedBy[$mapping->platformUserId] = $mapping->portalUserId;
        }

        $toMap = [];
        $already = [];
        $unmatched = [];
        $conflicts = [];
        foreach ($users as $user) {
            $ids = $found[$user->email] ?? [];
            $mapped = $existing[$user->id] ?? null;

            if (count($ids) > 1) {
                $conflicts[] = ['portalUserId' => $user->id, 'email' => $user->email, 'reason' => 'More than one platform user holds this email.'];
                continue;
            }
            if ($mapped !== null) {
                if ($ids === [] || $ids[0] === $mapped->platformUserId) {
                    $already[] = ['portalUserId' => $user->id, 'email' => $user->email, 'platformUserId' => $mapped->platformUserId];
                } else {
                    $conflicts[] = ['portalUserId' => $user->id, 'email' => $user->email, 'reason' => 'Already mapped to a different platform user; the map is not changed.'];
                }
                continue;
            }
            if ($ids === []) {
                $unmatched[] = ['portalUserId' => $user->id, 'email' => $user->email, 'name' => $user->name, 'active' => $user->active];
                continue;
            }
            $platformUserId = $ids[0];
            if (isset($claimedBy[$platformUserId])) {
                $conflicts[] = ['portalUserId' => $user->id, 'email' => $user->email, 'reason' => 'That platform user is already mapped from portal user ' . $claimedBy[$platformUserId] . '.'];
                continue;
            }
            $claimedBy[$platformUserId] = $user->id;
            $toMap[] = ['portalUserId' => $user->id, 'email' => $user->email, 'name' => $user->name, 'platformUserId' => $platformUserId];
        }

        return new FinancePortalUserPlan($toMap, $already, $unmatched, $conflicts, $rejected);
    }

    /** Writes the plan's matches. Returns how many rows were added. */
    public function apply(FinancePortalUserPlan $plan, DateTimeImmutable $at): int
    {
        $added = 0;
        foreach ($plan->toMap as $match) {
            if ($this->map->add(new FinancePortalUserMapping($match['portalUserId'], $match['email'], $match['platformUserId'], $at))) {
                ++$added;
            }
        }

        return $added;
    }
}
