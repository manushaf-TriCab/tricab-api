<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\AssistantTurnTrace;

final readonly class FallbackAssistantResponseProvider implements AssistantResponseProvider
{
    public function __construct(
        private AssistantResponseProvider $primary,
        private AssistantResponseProvider $fallback,
        private AssistantTurnTrace $trace = new AssistantTurnTrace(),
    ) {
    }

    public function generate(AssistantGrounding $grounding): AssistantProviderResult
    {
        try {
            return $this->primary->generate($grounding);
        } catch (\Throwable $exception) {
            $this->logPrimaryFailure($exception);
            $this->trace->recordFallback($exception);

            return $this->trace->measure('fallback_generate', fn (): AssistantProviderResult => $this->fallback->generate($grounding));
        }
    }

    /**
     * The degraded path has to be visible.
     *
     * This previously caught and discarded, which meant a broken agent was
     * indistinguishable from a working one: the customer still got an answer, the
     * response still said 200, and the only trace was `provider.mode` reading
     * `deterministic-search` in a payload nobody reads. Foundry could have been
     * failing on every request since it was switched on and nothing would say so.
     *
     * The message is logged, not the grounding — a turn can carry customer text and
     * unpublished catalogue data, and this lands in the App Service log stream.
     */
    private function logPrimaryFailure(\Throwable $exception): void
    {
        error_log(json_encode([
            'event' => 'assistant_primary_provider_failed',
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'previous' => $exception->getPrevious()?->getMessage(),
        ], JSON_UNESCAPED_SLASHES) ?: '{"event":"assistant_primary_provider_failed"}');
    }
}
