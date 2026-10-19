<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Contract;

use TriCab\Core\CableFit\Domain\ResearchModelFailed;
use TriCab\Core\CableFit\Domain\ResearchModelResponse;

/**
 * The model behind the research agent: a web-search-capable Claude. The Foundry implementation lives in the
 * API; tests use a fake. The model is advisory input to a person and nothing it says is acted on.
 */
interface ResearchModel
{
    /**
     * Run the exchange to its end: search (within $allowedDomains, at most $maxSearches times), continue
     * after a pause, and return every content block with the cost.
     *
     * @param list<string> $allowedDomains
     *
     * @throws ResearchModelFailed when no usable answer came back (transport, refusal, cut off, too long)
     */
    public function research(string $system, string $user, array $allowedDomains, int $maxSearches): ResearchModelResponse;
}
