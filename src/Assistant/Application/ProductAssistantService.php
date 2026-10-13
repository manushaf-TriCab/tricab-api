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
        private readonly AssistantTurnTrace $trace,
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
        $this->trace->begin();
        $this->trace->note('catalogueVisibility', $catalogueVisibility->value);
        try {
            $reply = $this->replyTraced($message, $searchQuery, $regionCode, $conversationId, $catalogueVisibility);
            $this->trace->note('outcome', 'ok');

            return $reply;
        } catch (\Throwable $exception) {
            $this->trace->note('outcome', 'error:' . $exception::class);
            throw $exception;
        } finally {
            $this->trace->emit();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function replyTraced(
        string $message,
        ?string $searchQuery,
        ?string $regionCode,
        ?string $conversationId,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): array {
        $conversation = $this->trace->measure('conversation_load', fn (): AssistantConversation => $this->conversations->getOrCreate(
            $conversationId,
            $regionCode,
            $catalogueVisibility,
        ));
        $this->trace->note('historyTurns', count($conversation->turns()));
        $searchPlan = $this->trace->measure(
            'interpret',
            fn (): AssistantSearchPlan => $this->queryInterpreter->interpret($message, $searchQuery, $regionCode),
        );
        [$items, $matchedSearchTerm] = $this->trace->measure('catalogue_search', fn (): array => $this->findVisibleProducts(
            $searchPlan,
            $regionCode,
            $catalogueVisibility,
        ));

        $products = $this->trace->measure('product_grounding', fn (): array => array_map(
            fn (ProductSearchResult $item): GroundedProduct => $this->productGrounder->fromSearchResult(
                $item,
                $regionCode,
                $catalogueVisibility,
            ),
            $items,
        ));
        $this->trace->note('groundedProducts', count($products));

        $grounding = new AssistantGrounding(
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
        );
        $providerResult = $this->trace->measure(
            'generate',
            fn (): AssistantProviderResult => $this->provider->generate($grounding),
        );
        $this->trace->note('provider', $providerResult->provider);
        // The turn keeps the provider's original text, including any choice
        // list lifted out below, so later turns still show the agent what it
        // offered when the customer answers with just "copper".
        $this->trace->measure('conversation_save', fn () => $this->conversations->appendTurn($conversation, new AssistantConversationTurn(
            userMessage: trim($message),
            assistantMessage: $providerResult->text,
        )));

        $clarification = AssistantClarificationOptions::extract($providerResult->text);

        // The pre-search products are a fallback for a provider that has no
        // cards of its own — the deterministic templates. A provider that
        // curates its cards has already decided, and an empty set from one of
        // those means "the answer names nothing to offer", not "nothing was
        // found": substituting here would put back the research cards the
        // agent just filtered out.
        $cards = ($providerResult->cards !== [] || $providerResult->curatesCards)
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
