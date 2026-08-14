<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

use Throwable;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewDetail;
use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Application\Service\GetAdminProductCustomerViewService;
use TriCab\Core\Product\Application\Service\SearchProductsService;

final class ProductAssistantService
{
    public function __construct(
        private readonly SearchProductsService $searchProducts,
        private readonly GetPublicProductService $getPublicProduct,
        private readonly GetAdminProductCustomerViewService $getStaffProduct,
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
        $conversation = $this->conversations->getOrCreate($conversationId);
        $searchPlan = $this->queryInterpreter->interpret($message, $searchQuery, $regionCode);
        [$items, $matchedSearchTerm] = $this->findVisibleProducts(
            $searchPlan,
            $regionCode,
            $catalogueVisibility,
        );

        $products = array_map(
            fn (ProductSearchResult $item): GroundedProduct => $this->groundProduct(
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
        ));
        $conversation->append(new AssistantConversationTurn(
            userMessage: trim($message),
            assistantMessage: $providerResult->text,
        ));

        return [
            'conversation' => [
                'publicId' => $conversation->publicId,
                'persisted' => false,
            ],
            'message' => [
                'publicId' => self::uuid(),
                'role' => 'assistant',
                'text' => $providerResult->text,
                'cards' => array_map(
                    static fn (GroundedProduct $product): array => $product->toCard(),
                    $products,
                ),
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
            'capabilities' => $catalogueVisibility === AssistantCatalogueVisibility::STAFF
                ? ['public-product-search', 'staff-product-search']
                : ['public-product-search'],
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

    private function groundProduct(
        ProductSearchResult $item,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): GroundedProduct
    {
        if ($catalogueVisibility === AssistantCatalogueVisibility::STAFF) {
            try {
                return $this->fromCustomerView($this->getStaffProduct->execute($item->publicId, $regionCode));
            } catch (Throwable) {
                // The safe search projection remains usable if a richer preview
                // races with an update or has incomplete migrated subresources.
            }
        }

        if ($item->slug !== null) {
            try {
                return $this->fromDetail($this->getPublicProduct->bySlug($item->slug, $regionCode));
            } catch (Throwable) {
                // The public search projection remains safe and useful when a detail
                // lookup races with catalogue publication or indexing.
            }
        }

        return new GroundedProduct(
            publicId: $item->publicId,
            productName: $item->productName,
            productCode: $item->productCode,
            slug: $item->slug,
            seriesName: $item->seriesName,
            categoryName: $item->categoryName,
            summary: null,
            applications: [],
            specifications: [],
            thumbnailUrl: $item->thumbnailUrl,
            statusCode: $item->statusCode,
        );
    }

    private function fromDetail(ProductPublicDetail $detail): GroundedProduct
    {
        $specifications = [];
        foreach ($detail->specifications as $specification) {
            $value = trim((string) $specification->displayValue);
            if ($value === '') {
                continue;
            }
            if ($specification->unit !== null && trim($specification->unit) !== '') {
                $value .= ' ' . trim($specification->unit);
            }
            $specifications[] = [
                'name' => $specification->name,
                'value' => $value,
            ];
        }

        return new GroundedProduct(
            publicId: $detail->publicId,
            productName: $detail->productName,
            productCode: $detail->productCode,
            slug: $detail->slug,
            seriesName: $detail->seriesName,
            categoryName: $detail->categoryName,
            summary: $detail->shortDescription ?? $detail->fullDescription,
            applications: $detail->applicationNames,
            specifications: $specifications,
            thumbnailUrl: $detail->thumbnailUrl,
            statusCode: 'published',
        );
    }

    private function fromCustomerView(ProductCustomerViewDetail $detail): GroundedProduct
    {
        $specifications = [];
        foreach ($detail->specifications as $specification) {
            $value = trim((string) $specification->displayValue);
            if ($value === '') {
                continue;
            }
            if ($specification->unit !== null && trim($specification->unit) !== '') {
                $value .= ' ' . trim($specification->unit);
            }
            $specifications[] = [
                'name' => $specification->name,
                'value' => $value,
            ];
        }

        return new GroundedProduct(
            publicId: $detail->publicId,
            productName: $detail->productName,
            productCode: $detail->productCode,
            slug: $detail->primarySlug,
            seriesName: $detail->seriesName,
            categoryName: $detail->categoryName,
            summary: $detail->shortDescription ?? $detail->fullDescription,
            applications: $detail->applicationNames,
            specifications: $specifications,
            thumbnailUrl: $detail->thumbnailUrl,
            statusCode: $detail->statusCode,
        );
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
