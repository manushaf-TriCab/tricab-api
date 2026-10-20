<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsDraft;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsEvent;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsReview;

/**
 * The facts `RfqAnalyticsService` computes from, read whole: the store holds
 * about a thousand RFQs a year, so every formula runs over parsed values in
 * PHP rather than over timestamp text in SQL.
 */
interface RfqAnalyticsSource
{
    /** @return list<AnalyticsDraft> every RFQ, archived included */
    public function drafts(): array;

    /** @return list<AnalyticsEvent> activity of the `AnalyticsEvent::TYPES` types */
    public function events(): array;

    /** @return list<AnalyticsReview> every review session, legacy ones included */
    public function reviews(): array;

    /**
     * Display names for assignee addresses that map to exactly one platform
     * user, as the queue matches them. Addresses with no user are absent.
     *
     * @param list<string> $emails lower-cased
     *
     * @return array<string, array{userId: int, name: string}>
     */
    public function people(array $emails): array;
}
