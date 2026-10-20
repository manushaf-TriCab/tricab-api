<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use TriCab\Core\CableFit\Domain\CandidateListRow;
use TriCab\Core\CableFit\Domain\ResearchRun;

/**
 * One row of the design list. The NPR number is null when the design has no NPR request or the
 * user may not read it; the latest research run is null when the design was never researched.
 */
final readonly class CandidateListItem
{
    public function __construct(
        public CandidateListRow $row,
        public ?string $nprRequestNumber,
        public bool $stale,
        public ?ResearchRun $latestResearch,
    ) {
    }
}
