<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProductGrounder;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\GroundedProduct;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\Service\SearchProductsService;

/**
 * Rebuilds the old deterministic grounding only after Foundry fails. This keeps
 * pre-search out of the successful agent path without degrading local laptops
 * or a temporary Foundry outage to an ungrounded canned response.
 */
final readonly class SearchGroundedDeterministicAssistantResponseProvider implements AssistantResponseProvider
{
    public function __construct(
        private SearchProductsService $searchProducts,
        private AssistantProductGrounder $productGrounder,
        private DeterministicAssistantQueryInterpreter $queryInterpreter,
        private DeterministicAssistantResponseProvider $responseProvider,
    ) {
    }

    public function generate(AssistantGrounding $grounding): AssistantProviderResult
    {
        $visibility = AssistantCatalogueVisibility::tryFrom($grounding->catalogueVisibility)
            ?? AssistantCatalogueVisibility::PUBLIC;
        $plan = $this->queryInterpreter->interpret(
            $grounding->userMessage,
            $grounding->searchQuery,
            $grounding->regionCode,
        );
        $items = [];
        $matchedSearchTerm = null;
        foreach ($plan->searchTerms as $term) {
            $result = $this->searchProducts->execute(new ProductSearchCriteria(
                query: $term,
                page: 1,
                pageSize: 5,
                regionCode: $grounding->regionCode,
                publishedOnly: $visibility->publishedOnly(),
                statusCodes: $visibility->allowedStatusCodes(),
            ));
            if ($result->items !== []) {
                $items = $result->items;
                $matchedSearchTerm = $term;
                break;
            }
        }

        $products = array_map(
            fn (ProductSearchResult $item): GroundedProduct => $this->productGrounder->fromSearchResult(
                $item,
                $grounding->regionCode,
                $visibility,
            ),
            $items,
        );

        $response = $this->responseProvider->generate(new AssistantGrounding(
            userMessage: $grounding->userMessage,
            searchQuery: $plan->originalQuery,
            regionCode: $grounding->regionCode,
            products: $products,
            canonicalIntent: $plan->canonicalIntent,
            searchTerms: $plan->searchTerms,
            matchedSearchTerm: $matchedSearchTerm,
            clarificationQuestion: $plan->clarificationQuestion,
            queryInstructionVersion: $plan->instructionVersion,
            catalogueVisibility: $visibility->value,
            conversationTurns: $grounding->conversationTurns,
        ));

        return new AssistantProviderResult(
            text: $response->text,
            provider: $response->provider,
            instructionVersion: $response->instructionVersion,
            cards: array_map(static fn (GroundedProduct $product): array => $product->toCard(), $products),
        );
    }
}
