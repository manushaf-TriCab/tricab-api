<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use DateTimeImmutable;
use TriCab\Core\Identity\Contract\ExpiredEmailOtpChallengePurger;

/**
 * In-memory runs keep challenges only for the life of the process, so there is
 * never anything to purge.
 */
final class InMemoryExpiredEmailOtpChallengePurger implements ExpiredEmailOtpChallengePurger
{
    public function countPurgeable(DateTimeImmutable $createdBefore, DateTimeImmutable $nowUtc): int
    {
        return 0;
    }

    public function purge(DateTimeImmutable $createdBefore, DateTimeImmutable $nowUtc, int $limit): int
    {
        return 0;
    }
}
