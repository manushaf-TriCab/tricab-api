<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/** The candidate already has a queued or running research run; only one is allowed at a time. */
final class ResearchRunActive extends CableFitException
{
    public function __construct(public readonly string $activeRunPublicId)
    {
        parent::__construct('A research run is already queued or running for this candidate.');
    }
}
