<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Application\DTO\CompletionFeed\CompletionFeedEntry;
use TriCab\Core\Onboarding\Contract\OnboardingCompletionFeedRepository;

final class InMemoryOnboardingCompletionFeedRepository implements OnboardingCompletionFeedRepository
{
    /** @var list<CompletionFeedEntry> */
    private array $entries = [];

    public function after(int $afterSequence, int $limit, DateTimeImmutable $settledBefore): array
    {
        $matching = array_values(array_filter(
            $this->entries,
            static fn (CompletionFeedEntry $e): bool => $e->sequence > $afterSequence
                && new DateTimeImmutable($e->occurredAtUtc) < $settledBefore,
        ));
        usort($matching, static fn (CompletionFeedEntry $a, CompletionFeedEntry $b): int => $a->sequence <=> $b->sequence);

        return array_slice($matching, 0, $limit);
    }

    public function with(CompletionFeedEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
