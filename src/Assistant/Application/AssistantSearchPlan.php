<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantSearchPlan
{
    /**
     * @param list<string> $searchTerms
     */
    public function __construct(
        public string $originalQuery,
        public ?string $canonicalIntent,
        public array $searchTerms,
        public ?string $clarificationQuestion,
        public string $instructionVersion,
        public bool $semanticInterpretationRecommended = false,
    ) {
    }
}
