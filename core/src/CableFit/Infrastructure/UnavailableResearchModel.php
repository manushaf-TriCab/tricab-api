<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Infrastructure;

use TriCab\Core\CableFit\Contract\ResearchModel;
use TriCab\Core\CableFit\Domain\ResearchModelFailed;
use TriCab\Core\CableFit\Domain\ResearchModelResponse;

/** The model when Foundry is not configured: every call fails the run with a clear message. */
final class UnavailableResearchModel implements ResearchModel
{
    public function research(string $system, string $user, array $allowedDomains, int $maxSearches): ResearchModelResponse
    {
        throw new ResearchModelFailed('The research model is not configured.');
    }
}
