<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\GroundedProduct;
use TriCab\Api\Assistant\Infrastructure\DeterministicAssistantResponseProvider;
use TriCab\Api\Assistant\Infrastructure\FallbackAssistantResponseProvider;

final class FallbackAssistantResponseProviderTest extends TestCase
{
    public function test_uses_the_deterministic_provider_when_the_primary_fails(): void
    {
        $primary = new class implements AssistantResponseProvider {
            public function generate(AssistantGrounding $grounding): AssistantProviderResult
            {
                throw new RuntimeException('Provider unavailable');
            }
        };
        $provider = new FallbackAssistantResponseProvider(
            $primary,
            new DeterministicAssistantResponseProvider(),
        );

        $result = $provider->generate(new AssistantGrounding(
            userMessage: 'Find KL',
            searchQuery: 'KL',
            regionCode: 'AU',
            products: [$this->publishedProduct()],
        ));

        self::assertSame('deterministic-search', $result->provider);
        self::assertStringContainsString('Published Cable', $result->text);
    }

    public function test_returns_the_primary_result_when_it_succeeds(): void
    {
        $primary = new class implements AssistantResponseProvider {
            public function generate(AssistantGrounding $grounding): AssistantProviderResult
            {
                return new AssistantProviderResult('Live answer', 'foundry-claude', 'v1');
            }
        };
        $provider = new FallbackAssistantResponseProvider(
            $primary,
            new DeterministicAssistantResponseProvider(),
        );

        $result = $provider->generate(new AssistantGrounding(
            'Question',
            'query',
            null,
            [$this->publishedProduct()],
        ));

        self::assertSame('Live answer', $result->text);
        self::assertSame('foundry-claude', $result->provider);
    }

    public function test_skips_the_primary_provider_when_no_products_are_grounded(): void
    {
        $primary = new class implements AssistantResponseProvider {
            public function generate(AssistantGrounding $grounding): AssistantProviderResult
            {
                throw new RuntimeException('Primary must not be called without catalogue grounding.');
            }
        };
        $provider = new FallbackAssistantResponseProvider(
            $primary,
            new DeterministicAssistantResponseProvider(),
        );

        $result = $provider->generate(new AssistantGrounding(
            userMessage: 'What fire rated cables do you have?',
            searchQuery: 'What fire rated cables do you have?',
            regionCode: null,
            products: [],
            canonicalIntent: 'Fire Resistant',
            searchTerms: ['Fire Resistant', 'FR'],
            clarificationQuestion: 'What voltage do you need?',
        ));

        self::assertSame('deterministic-search', $result->provider);
        self::assertStringContainsString('Fire Resistant', $result->text);
    }

    private function publishedProduct(): GroundedProduct
    {
        return new GroundedProduct(
            publicId: 'product-1',
            productName: 'Published Cable',
            productCode: null,
            slug: null,
            seriesName: null,
            categoryName: null,
            summary: null,
            applications: [],
            specifications: [],
            thumbnailUrl: null,
        );
    }
}
