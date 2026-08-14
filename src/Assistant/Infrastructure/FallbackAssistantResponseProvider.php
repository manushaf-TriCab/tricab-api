<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;

final readonly class FallbackAssistantResponseProvider implements AssistantResponseProvider
{
    public function __construct(
        private AssistantResponseProvider $primary,
        private AssistantResponseProvider $fallback,
    ) {
    }

    public function generate(AssistantGrounding $grounding): AssistantProviderResult
    {
        if ($grounding->products === []) {
            return $this->fallback->generate($grounding);
        }

        try {
            return $this->primary->generate($grounding);
        } catch (\Throwable) {
            return $this->fallback->generate($grounding);
        }
    }
}
