<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO\Analytics;

use DateTimeImmutable;

/**
 * One RFQ as the analytics read it: only the columns the formulas use, with
 * times parsed and text normalised the way tc-web's `_summary_from_row` did.
 */
final class AnalyticsDraft
{
    public function __construct(
        public readonly string $id,
        /** Null when the stored text does not parse; such a draft is in no window. */
        public readonly ?DateTimeImmutable $createdAt,
        /**
         * The stored status after the source's aliasing (`pending` → `new`,
         * `push_failed` → `failed`): new, in_progress, ready_to_sync, blocked,
         * failed or synced. Anything else is kept as it is.
         */
        public readonly string $status,
        public readonly bool $archived,
        public readonly ?DateTimeImmutable $syncedAt,
        public readonly ?DateTimeImmutable $dueAt,
        /** Lower-cased and trimmed; '' when nobody is assigned. */
        public readonly string $assigneeEmail,
        /** The stored `source` (email, web, alternative, ...). */
        public readonly string $source,
        /** Upper-cased; '' when the RFQ carries none. */
        public readonly string $branch,
    ) {
    }
}
