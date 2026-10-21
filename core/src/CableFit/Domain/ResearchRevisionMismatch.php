<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/** Evaluate named a saved revision that is no longer the design's current one: someone else saved since. */
final class ResearchRevisionMismatch extends CableFitException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('The design has been saved since; reload it and evaluate again.');
    }
}
