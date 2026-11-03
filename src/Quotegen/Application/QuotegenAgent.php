<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

interface QuotegenAgent
{
    /**
     * Runs the model and tool rounds for one user turn.
     *
     * @param list<array{role: string, content: string}> $history earlier turns, ending with the user's new message
     * @param callable(string, array<string, mixed>): void $emit the turn's SSE events: progress, tool, quote_changed, delta
     *
     * @throws QuotegenBudgetExhausted
     * @throws \TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable
     */
    public function run(QuotegenTurnContext $context, array $history, callable $emit): QuotegenAgentResult;
}
