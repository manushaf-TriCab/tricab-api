<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\AssistantTurnTrace;
use TriCab\Api\Assistant\Application\GroundedProduct;
use TriCab\Api\Assistant\Infrastructure\DeterministicAssistantResponseProvider;
use TriCab\Api\Assistant\Infrastructure\FallbackAssistantResponseProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;

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

    public function test_traces_the_fallback_and_its_reason(): void
    {
        $primary = new class implements AssistantResponseProvider {
            public function generate(AssistantGrounding $grounding): AssistantProviderResult
            {
                throw new RuntimeException('Foundry Messages request failed.');
            }
        };
        $trace = new AssistantTurnTrace();
        $trace->begin();
        $provider = new FallbackAssistantResponseProvider(
            $primary,
            new DeterministicAssistantResponseProvider(),
            $trace,
        );

        $provider->generate(new AssistantGrounding('Find KL', 'KL', 'AU', [$this->publishedProduct()]));

        $summary = $trace->summary();
        self::assertTrue($summary['fellBack']);
        self::assertNotNull($summary['fallback']);
        self::assertSame('Foundry Messages request failed.', $summary['fallback']['message']);
        self::assertArrayHasKey('fallback_generate', $summary['phasesMs']);
    }

    public function test_the_fallback_reply_keeps_the_spec_the_agent_had_gathered(): void
    {
        $primary = new class implements AssistantResponseProvider {
            public function generate(AssistantGrounding $grounding): AssistantProviderResult
            {
                $exception = new FoundryProviderUnavailable('Foundry Messages response did not contain text.');
                $exception->attachPartialSpec(['coreCount' => 4]);

                throw $exception;
            }
        };
        $provider = new FallbackAssistantResponseProvider($primary, new DeterministicAssistantResponseProvider());

        $result = $provider->generate(new AssistantGrounding('Find KL', 'KL', 'AU', [$this->publishedProduct()]));

        self::assertSame('deterministic-search', $result->provider);
        self::assertSame(['coreCount' => 4], $result->spec);
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

    public function test_calls_the_primary_provider_when_pre_search_found_no_products(): void
    {
        $primary = new class implements AssistantResponseProvider {
            public function generate(AssistantGrounding $grounding): AssistantProviderResult
            {
                return new AssistantProviderResult(
                    'I can search with tools or ask a targeted question.',
                    'foundry-claude-agent',
                    'v3',
                );
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

        self::assertSame('foundry-claude-agent', $result->provider);
        self::assertStringContainsString('search with tools', $result->text);
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
