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
}
