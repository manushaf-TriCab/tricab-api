<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use TriCab\Core\CableFit\Domain\CableFitCandidate;

/**
 * One row of the candidate list for an NPR request.
 */
final readonly class CandidateSummary
{
    public function __construct(
        public CableFitCandidate $candidate,
        public ?int $sourceNprRevision,
        public bool $stale,
        public ?string $staleReason,
    ) {
    }
}
