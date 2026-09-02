<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantQueryInterpreter;
use TriCab\Api\Assistant\Application\AssistantSearchPlan;

/**
 * Agent mode deliberately performs no search before the model sees the request.
 * Search selection, retries and clarification now happen through bounded tools.
 */
final class AgentAssistantQueryInterpreter implements AssistantQueryInterpreter
{
    private const INSTRUCTION_VERSION = 'product-assistant-agent-v3';

    public function interpret(
        string $message,
        ?string $explicitSearchQuery = null,
        ?string $regionCode = null,
    ): AssistantSearchPlan {
        unset($regionCode);
        $query = trim($explicitSearchQuery ?? '');
        if ($query === '') {
            $query = trim($message);
        }

        return new AssistantSearchPlan(
            originalQuery: $query,
            canonicalIntent: null,
            searchTerms: [],
            clarificationQuestion: null,
            instructionVersion: self::INSTRUCTION_VERSION,
        );
    }
}
