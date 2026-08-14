<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantGrounding
{
    /**
     * @param list<GroundedProduct> $products
     * @param list<string> $searchTerms
     */
    public function __construct(
        public string $userMessage,
        public string $searchQuery,
        public ?string $regionCode,
        public array $products,
        public ?string $canonicalIntent = null,
        public array $searchTerms = [],
        public ?string $matchedSearchTerm = null,
        public ?string $clarificationQuestion = null,
        public string $queryInstructionVersion = 'product-assistant-query-legacy-v1',
        public string $catalogueVisibility = 'public',
    ) {
    }
}
