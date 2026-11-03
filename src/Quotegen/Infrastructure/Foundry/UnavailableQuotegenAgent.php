<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Infrastructure\Foundry;

use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;
use TriCab\Api\Quotegen\Application\QuotegenAgent;
use TriCab\Api\Quotegen\Application\QuotegenAgentResult;
use TriCab\Api\Quotegen\Application\QuotegenTurnContext;

/** Stands in while Foundry is not configured: every turn ends in `provider_unavailable`. */
final class UnavailableQuotegenAgent implements QuotegenAgent
{
    public function run(QuotegenTurnContext $context, array $history, callable $emit): QuotegenAgentResult
    {
        throw new FoundryProviderUnavailable('Foundry is not configured for the Quotegen assistant.');
    }
}
