<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantProviderResult
{
    /**
     * @param list<array<string, mixed>> $cards
     * @param list<string> $toolNames
     * @param bool $curatesCards Whether this provider chose its own card set,
     *        so an empty `$cards` means "show none" rather than "I had none to
     *        give". `ProductAssistantService` substitutes its pre-search
     *        products for the latter, which would undo the agent's filtering
     *        and put back the very cards the answer does not mention.
     * @param array<string, mixed>|null $spec The last product-finder requirement
     *        this turn put (see `AssistantReplySpec`); null when it made none.
     */
    public function __construct(
        public string $text,
        public string $provider,
        public string $instructionVersion,
        public array $cards = [],
        public array $toolNames = [],
        public bool $curatesCards = false,
        public ?array $spec = null,
    ) {
    }
}
