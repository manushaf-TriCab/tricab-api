<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\DeterministicAssistantQueryInterpreter;

final class DeterministicAssistantQueryInterpreterTest extends TestCase
{
    public function test_fire_rated_question_maps_to_confirmed_fire_resistant_terms(): void
    {
        $plan = (new DeterministicAssistantQueryInterpreter())->interpret(
            'What fire rated cables do you have?',
        );

        self::assertSame('Fire Resistant', $plan->canonicalIntent);
        self::assertSame('Fire Resistant', $plan->searchTerms[0]);
        self::assertContains('FR', $plan->searchTerms);
        self::assertContains('Radox', $plan->searchTerms);
        self::assertFalse($plan->semanticInterpretationRecommended);
        self::assertSame('product-assistant-query-rules-v1', $plan->instructionVersion);
    }

    public function test_lszh_does_not_get_promoted_to_fire_resistant(): void
    {
        $plan = (new DeterministicAssistantQueryInterpreter())->interpret(
            'Show me LSZH cables.',
        );

        self::assertSame('Low Smoke Zero Halogen (LSZH)', $plan->canonicalIntent);
        self::assertNotSame('Fire Resistant', $plan->canonicalIntent);
    }

    public function test_explicit_search_query_remains_authoritative_for_follow_up_messages(): void
    {
        $plan = (new DeterministicAssistantQueryInterpreter())->interpret(
            'What applications does it support?',
            'KL',
        );

        self::assertSame('KL', $plan->originalQuery);
        self::assertSame(['KL'], $plan->searchTerms);
        self::assertNull($plan->canonicalIntent);
        self::assertFalse($plan->semanticInterpretationRecommended);
    }

    public function test_conversational_framing_is_removed_from_fallback_terms(): void
    {
        $plan = (new DeterministicAssistantQueryInterpreter())->interpret(
            'Tell me about KL cable.',
        );

        self::assertSame(['KL'], $plan->searchTerms);
    }

    /**
     * The hero search box sends the raw query as `searchQuery` alongside the prompt. That used to
     * become the single search term, so with AND-across-token matching one unmatchable word sank
     * the whole search and the assistant answered "I could not find a TriCab product".
     */
    public function test_explicit_search_query_degrades_past_terms_that_match_nothing(): void
    {
        $plan = (new DeterministicAssistantQueryInterpreter())->interpret(
            'Tell me about I need LSZH marine cable, 4 cores, 4mm2.',
            'I need LSZH marine cable, 4 cores, 4mm2',
        );

        self::assertSame('I need LSZH marine cable, 4 cores, 4mm2', $plan->originalQuery);
        self::assertSame('I need LSZH marine cable, 4 cores, 4mm2', $plan->searchTerms[0]);
        self::assertGreaterThan(1, count($plan->searchTerms));
        self::assertContains('LSZH', $plan->searchTerms);
    }

    public function test_explicit_search_query_still_reaches_the_intent_rules(): void
    {
        $plan = (new DeterministicAssistantQueryInterpreter())->interpret(
            'Tell me about low smoke marine cable.',
            'low smoke marine cable',
        );

        self::assertSame('Low Smoke Zero Halogen (LSZH)', $plan->canonicalIntent);
        self::assertContains('HFFR', $plan->searchTerms);
    }

    public function test_explicit_product_code_is_tried_verbatim_before_anything_else(): void
    {
        $plan = (new DeterministicAssistantQueryInterpreter())->interpret(
            'Tell me about BHA-PATN/4C4RE.',
            'BHA-PATN/4C4RE',
        );

        self::assertSame('BHA-PATN/4C4RE', $plan->searchTerms[0]);
    }

    public function test_explicit_search_query_never_asks_for_semantic_interpretation(): void
    {
        $plan = (new DeterministicAssistantQueryInterpreter())->interpret(
            'Tell me about 4 core 16mm2 armoured.',
            '4 core 16mm2 armoured',
        );

        self::assertFalse($plan->semanticInterpretationRecommended);
    }
}
