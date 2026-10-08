<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Contract;

/** Counts attempts against a key in a window; Tri-AR uses it on the public form, the one route with no sign-in. */
interface ArRateLimiter
{
    /** Records one attempt and says whether it is within `$limit` for the last `$windowSeconds`. */
    public function allow(string $key, int $limit, int $windowSeconds): bool;
}
