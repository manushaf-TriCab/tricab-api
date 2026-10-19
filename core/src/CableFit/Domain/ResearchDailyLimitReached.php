<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

use DateTimeImmutable;

/** The user has already started the daily number of research runs in the last 24 hours. */
final class ResearchDailyLimitReached extends CableFitException
{
    /**
     * @param int               $limit   the daily cap that was reached
     * @param DateTimeImmutable $retryAt when the oldest run in the window turns 24 hours old
     */
    public function __construct(public readonly int $limit, public readonly DateTimeImmutable $retryAt)
    {
        parent::__construct(sprintf('You have started %d research runs in the last 24 hours, the daily limit.', $limit));
    }
}
