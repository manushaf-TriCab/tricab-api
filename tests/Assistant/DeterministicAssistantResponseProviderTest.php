<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Infrastructure\DeterministicAssistantResponseProvider;

/**
 * The fallback speaks as TriCab's sales team ("we don't currently carry…"),
 * not as an assistant reporting a search ("I could not find…"), and an empty
 * match is never described as a stock state.
 */
final class DeterministicAssistantResponseProviderTest extends TestCase
{
    public function test_no_match_with_an_intent_speaks_for_the_company_and_keeps_the_question(): void
    {
        $result = (new DeterministicAssistantResponseProvider())->generate(new AssistantGrounding(
            userMessage: 'fire rated cable',
            searchQuery: 'fire rated cable',
            regionCode: 'AU',
            products: [],
            canonicalIntent: 'Fire Resistant',
            clarificationQuestion: 'What voltage, conductor material, core count and construction do you need?',
        ));

        self::assertStringStartsWith('We don\'t currently carry any Fire Resistant cable in our published range', $result->text);
        self::assertStringContainsString('our sales team', $result->text);
        self::assertStringEndsWith('What voltage, conductor material, core count and construction do you need?', $result->text);
        self::assertSame('product-assistant-fallback-v4', $result->instructionVersion);
        $this->assertSalesVoice($result->text);
    }

    public function test_no_match_without_an_intent_offers_a_next_step(): void
    {
        $result = (new DeterministicAssistantResponseProvider())->generate(new AssistantGrounding(
            userMessage: 'zzz',
            searchQuery: 'zzz',
            regionCode: 'AU',
            products: [],
        ));

        self::assertStringStartsWith('We don\'t currently carry a product matching "zzz" in our published range.', $result->text);
        self::assertStringContainsString('contact our sales team', $result->text);
        $this->assertSalesVoice($result->text);
    }

    private function assertSalesVoice(string $text): void
    {
        self::assertDoesNotMatchRegularExpression('/\bI (could|found|understood|searched)\b/', $text);
        self::assertStringNotContainsStringIgnoringCase('in stock', $text);
        self::assertStringNotContainsStringIgnoringCase('catalogue', $text);
    }
}
