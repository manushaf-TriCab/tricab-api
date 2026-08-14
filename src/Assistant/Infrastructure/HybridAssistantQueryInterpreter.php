<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use Throwable;
use TriCab\Api\Assistant\Application\AssistantQueryInterpreter;
use TriCab\Api\Assistant\Application\AssistantSearchPlan;

final readonly class HybridAssistantQueryInterpreter implements AssistantQueryInterpreter
{
    public function __construct(
        private AssistantQueryInterpreter $rules,
        private AssistantQueryInterpreter $semantic,
    ) {
    }

    public function interpret(
        string $message,
        ?string $explicitSearchQuery = null,
        ?string $regionCode = null,
    ): AssistantSearchPlan {
        $fallback = $this->rules->interpret($message, $explicitSearchQuery, $regionCode);
        if (!$fallback->semanticInterpretationRecommended) {
            return $fallback;
        }

        try {
            return $this->semantic->interpret($message, $explicitSearchQuery, $regionCode);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
