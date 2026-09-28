<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;

/**
 * An RFQ's extraction state, derived from its reads and its coverage.
 *
 * Never stored as the truth and never set by a client: stored copies are a
 * projection for queue filtering, recomputed whenever a read or a revision
 * changes.
 *
 * Precedence, and why:
 *
 *  1. `attention` — a failed read or a standing proposal is something a person
 *     can act on now, even while another file is still being read.
 *  2. `reading` — rows may still change underneath the rep.
 *  3. `partial` — every read settled, yet input rows are unaccounted for.
 *  4. `complete`.
 */
final class ExtractionStatePolicy
{
    /**
     * @param list<ReaderJobStatus> $reads every read of this RFQ's documents
     * @param bool $coverageComplete input rows = kept + excluded + skipped, per
     *        the parser's source accounting (plan 3.4). Pass `true` only when
     *        the accounting balances, never because it was not measured.
     */
    public static function derive(array $reads, bool $coverageComplete): ExtractionState
    {
        foreach ($reads as $read) {
            if ($read->needsAttention()) {
                return ExtractionState::attention();
            }
        }

        foreach ($reads as $read) {
            if ($read->isInFlight()) {
                return ExtractionState::reading();
            }
        }

        return $coverageComplete ? ExtractionState::complete() : ExtractionState::partial();
    }
}
