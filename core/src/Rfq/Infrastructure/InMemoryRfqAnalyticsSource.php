<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsDraft;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsEvent;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsReview;
use TriCab\Core\Rfq\Contract\RfqAnalyticsSource;

/** Analytics facts held in memory: tests, and environments with no store (empty). */
final class InMemoryRfqAnalyticsSource implements RfqAnalyticsSource
{
    /**
     * @param list<AnalyticsDraft> $drafts
     * @param list<AnalyticsEvent> $events
     * @param list<AnalyticsReview> $reviews
     * @param array<string, array{userId: int, name: string}> $people lower-cased email => person
     */
    public function __construct(
        private readonly array $drafts = [],
        private readonly array $events = [],
        private readonly array $reviews = [],
        private readonly array $people = [],
    ) {
    }

    public function drafts(): array
    {
        return $this->drafts;
    }

    public function events(): array
    {
        return $this->events;
    }

    public function reviews(): array
    {
        return $this->reviews;
    }

    public function people(array $emails): array
    {
        return array_intersect_key($this->people, array_flip($emails));
    }
}
