<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\Service\SearchProductsService;

final class ProductAssistantService
{
    public function __construct(
        private readonly SearchProductsService $searchProducts,
        private readonly AssistantProductGrounder $productGrounder,
        private readonly AssistantQueryInterpreter $queryInterpreter,
        private readonly AssistantResponseProvider $provider,
        private readonly AssistantConversationRepository $conversations,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(
        string $message,
        ?string $searchQuery = null,
        ?string $regionCode = null,
        ?string $conversationId = null,
        AssistantCatalogueVisibility $catalogueVisibility = AssistantCatalogueVisibility::PUBLIC,
    ): array {
        $conversation = $this->conversations->getOrCreate(
            $conversationId,
            $regionCode,
            $catalogueVisibility,
        );
        $searchPlan = $this->queryInterpreter->interpret($message, $searchQuery, $regionCode);
        [$items, $matchedSearchTerm] = $this->findVisibleProducts(
            $searchPlan,
            $regionCode,
            $catalogueVisibility,
        );

        $products = array_map(
            fn (ProductSearchResult $item): GroundedProduct => $this->productGrounder->fromSearchResult(
                $item,
                $regionCode,
                $catalogueVisibility,
            ),
            $items,
        );

        $providerResult = $this->provider->generate(new AssistantGrounding(
            userMessage: trim($message),
            searchQuery: $searchPlan->originalQuery,
            regionCode: $regionCode,
            products: $products,
            canonicalIntent: $searchPlan->canonicalIntent,
            searchTerms: $searchPlan->searchTerms,
            matchedSearchTerm: $matchedSearchTerm,
            clarificationQuestion: $searchPlan->clarificationQuestion,
            queryInstructionVersion: $searchPlan->instructionVersion,
            catalogueVisibility: $catalogueVisibility->value,
            conversationTurns: $conversation->turns(),
        ));
        // The turn keeps the provider's original text, including any choice
        // list lifted out below, so later turns still show the agent what it
        // offered when the customer answers with just "copper".
        $this->conversations->appendTurn($conversation, new AssistantConversationTurn(
            userMessage: trim($message),
            assistantMessage: $providerResult->text,
        ));

        $clarification = AssistantClarificationOptions::extract($providerResult->text);

        $cards = $providerResult->cards !== []
            ? $providerResult->cards
            : array_map(
                static fn (GroundedProduct $product): array => $product->toCard(),
                $products,
            );

        $capabilities = $catalogueVisibility === AssistantCatalogueVisibility::STAFF
            ? ['public-product-search', 'staff-product-search']
            : ['public-product-search'];
        foreach ($providerResult->toolNames as $toolName) {
            $capabilities[] = 'assistant-tool:' . $toolName;
        }

        return [
            'conversation' => [
                'publicId' => $conversation->publicId,
                'persisted' => $this->conversations->persistsAcrossRequests(),
            ],
            'message' => [
                'publicId' => self::uuid(),
                'role' => 'assistant',
                'text' => $clarification->text,
                'cards' => $cards,
                'clarificationOptions' => $clarification->options,
                'citations' => [],
            ],
            'provider' => [
                'mode' => $providerResult->provider,
                'instructionVersion' => $providerResult->instructionVersion,
                'queryInstructionVersion' => $searchPlan->instructionVersion,
            ],
            'catalogue' => [
                'visibility' => $catalogueVisibility->value,
                'includesUnpublished' => $catalogueVisibility === AssistantCatalogueVisibility::STAFF,
            ],
            'capabilities' => array_values(array_unique($capabilities)),
        ];
    }

    /**
     * Search terms are ordered fallbacks. The first term with published matches
     * wins so a broad synonym cannot dilute a more specific catalogue match.
     *
     * @return array{0: list<ProductSearchResult>, 1: string|null}
     */
    private function findVisibleProducts(
        AssistantSearchPlan $plan,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): array
    {
        foreach ($plan->searchTerms as $term) {
            $result = $this->searchProducts->execute(new ProductSearchCriteria(
                query: $term,
                page: 1,
                pageSize: 5,
                regionCode: $regionCode,
                publishedOnly: $catalogueVisibility->publishedOnly(),
                statusCodes: $catalogueVisibility->allowedStatusCodes(),
            ));
            if ($result->items !== []) {
                return [$result->items, $term];
            }
        }

        return [[], null];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
