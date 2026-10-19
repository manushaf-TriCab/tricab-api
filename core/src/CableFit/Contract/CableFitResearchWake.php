<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Contract;

/**
 * Word to the research worker that a run has been queued. The worker wakes every minute; without this it
 * would have to ask the database each time, and a serverless database asked every minute never pauses.
 *
 * Signalling is best effort and never fails the request: the run row is the record, and the worker's
 * periodic check finds a run whose signal was lost.
 */
interface CableFitResearchWake
{
    public function signal(): void;
}
