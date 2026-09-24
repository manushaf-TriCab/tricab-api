<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use DateTimeImmutable;

/**
 * Removes spent email sign-in challenges (Onboarding Phase 5.3).
 *
 * A challenge row holds a code hash, the address it was sent to, and the IP and
 * user agent that asked for it. Nothing reads one after it expires, so keeping
 * every row ever issued is retention with no purpose. Separate from
 * {@see EmailOtpChallengeRepository} because nothing on the sign-in path should
 * be able to reach a bulk delete.
 */
interface ExpiredEmailOtpChallengePurger
{
    /** Challenges created before `$createdBefore` that have also expired by `$nowUtc`. */
    public function countPurgeable(DateTimeImmutable $createdBefore, DateTimeImmutable $nowUtc): int;

    /**
     * Deletes up to `$limit` of them and returns how many went.
     */
    public function purge(DateTimeImmutable $createdBefore, DateTimeImmutable $nowUtc, int $limit): int;
}
