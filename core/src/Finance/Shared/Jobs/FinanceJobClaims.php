<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Jobs;

use DateTimeImmutable;

/**
 * The right to run one slot of one job, held in the database so that two runners
 * (a slot swap runs two instances for a while) cannot both have it.
 *
 * A claim is a row keyed by job name and slot (`finance.job_runs`). Taking it is one
 * atomic statement. A claim still `running` after `$staleAfterSeconds` is a runner that
 * died; the next runner takes it over, and the dead one's token no longer finishes it.
 */
interface FinanceJobClaims
{
    /** A token for the claim, or null when another runner holds it or has finished the slot. */
    public function claim(string $job, string $slot, DateTimeImmutable $now, int $staleAfterSeconds): ?string;

    /**
     * Records how the run ended, only if `$token` still owns the claim.
     *
     * @param array<string, scalar|null> $summary
     */
    public function finish(string $job, string $slot, string $token, bool $succeeded, ?string $error, array $summary, DateTimeImmutable $at): void;
}
