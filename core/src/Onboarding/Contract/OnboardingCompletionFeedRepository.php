<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Application\DTO\CompletionFeed\CompletionFeedEntry;

/**
 * The completion feed's source (Phase 6): status events into or out of
 * `completed`, in sequence order.
 */
interface OnboardingCompletionFeedRepository
{
    /**
     * Up to `$limit` events with a sequence above `$afterSequence`, recorded
     * before `$settledBefore`, ascending.
     *
     * @return list<CompletionFeedEntry>
     */
    public function after(int $afterSequence, int $limit, DateTimeImmutable $settledBefore): array;
}
