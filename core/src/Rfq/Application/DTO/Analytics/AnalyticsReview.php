<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO\Analytics;

use DateTimeImmutable;

/**
 * One review session (`rfq_sessions`) with its `rfq_entries` already counted.
 *
 * An entry is "unchanged" when the final and system product code agree, the
 * final and system status agree, and no correction reason was given (tc-web
 * `quality_metrics`). Confidence tiers are the system's own score at match
 * time: high ≥ 0.85, medium ≥ 0.6, low below, unknown when there is none.
 */
final class AnalyticsReview
{
    /**
     * @param array<string, int> $reasons trimmed correction reason => entries
     */
    public function __construct(
        public readonly string $sessionId,
        /** '' when the session is not linked to a draft. */
        public readonly string $draftId,
        public readonly ?DateTimeImmutable $at,
        /** Sessions from before drafts were linked; excluded from every metric. */
        public readonly bool $legacyUnlinked,
        public readonly int $entries,
        public readonly int $unchanged,
        public readonly int $withReason,
        public readonly int $highConfidence,
        public readonly int $mediumConfidence,
        public readonly int $lowConfidence,
        public readonly int $unknownConfidence,
        public readonly array $reasons,
    ) {
    }
}
