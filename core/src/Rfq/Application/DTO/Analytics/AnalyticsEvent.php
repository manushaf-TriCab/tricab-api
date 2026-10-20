<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO\Analytics;

use DateTimeImmutable;

/** One `rfq_draft_activity` row of a type the analytics count. */
final class AnalyticsEvent
{
    public const CLAIMED = 'claimed';
    public const CONTENT_UPDATED = 'content_updated';
    public const WORKFLOW_UPDATED = 'workflow_updated';
    public const SYNC_SUCCEEDED = 'sync_succeeded';
    public const SYNC_FAILED = 'sync_failed';

    /** Someone started work on the RFQ (tc-web `FIRST_WORK_EVENTS`). */
    public const FIRST_WORK = [self::CLAIMED, self::CONTENT_UPDATED, self::WORKFLOW_UPDATED];

    public const TYPES = [...self::FIRST_WORK, self::SYNC_SUCCEEDED, self::SYNC_FAILED];

    public function __construct(
        public readonly string $draftId,
        public readonly string $type,
        public readonly DateTimeImmutable $at,
    ) {
    }
}
