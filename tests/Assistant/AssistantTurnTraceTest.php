<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Assistant\Application\AssistantTurnTrace;

final class AssistantTurnTraceTest extends TestCase
{
    public function test_measure_returns_the_work_result_and_accumulates_the_phase(): void
    {
        $trace = new AssistantTurnTrace();
        $trace->begin();

        self::assertSame('first', $trace->measure('lookup', static fn (): string => 'first'));
        $trace->measure('lookup', static function (): void {
            usleep(2_000);
        });

        $summary = $trace->summary();
        self::assertSame('assistant_turn_timing', $summary['event']);
        self::assertSame(['lookup'], array_keys($summary['phasesMs']));
        self::assertGreaterThanOrEqual(2, $summary['phasesMs']['lookup']);
        self::assertIsInt($summary['totalMs']);
    }

    public function test_measure_still_records_a_phase_that_throws(): void
    {
        $trace = new AssistantTurnTrace();
        $trace->begin();

        $this->expectException(RuntimeException::class);
        try {
            $trace->measure('generate', static fn (): string => throw new RuntimeException('Foundry timed out'));
        } finally {
            self::assertArrayHasKey('generate', $trace->summary()['phasesMs']);
        }
    }

    public function test_model_calls_keep_only_token_counts_and_total_their_time(): void
    {
        $trace = new AssistantTurnTrace();
        $trace->begin();

        $trace->recordModelCall(1_200, true, 'ok', 'tool_use', [
            'input_tokens' => 2_400,
            'output_tokens' => 80,
            'cache_read_input_tokens' => 0,
            'service_tier' => 'standard',
        ]);
        $trace->recordModelCall(900, true, 'ok', 'end_turn', ['input_tokens' => 2_700, 'output_tokens' => 210]);
        $trace->recordToolCall('search_product_codes', 140, true);

        $summary = $trace->summary();
        self::assertSame(2, $summary['modelCallCount']);
        self::assertSame(2_100, $summary['modelMs']);
        self::assertSame(140, $summary['toolMs']);
        self::assertSame(
            ['input_tokens' => 2_400, 'output_tokens' => 80, 'cache_read_input_tokens' => 0],
            $summary['modelCalls'][0]['usage'],
        );
        self::assertFalse($summary['fellBack']);
    }

    public function test_begin_clears_the_previous_turn(): void
    {
        $trace = new AssistantTurnTrace();
        $trace->begin();
        $trace->note('provider', 'foundry-claude-agent');
        $trace->recordModelCall(500, true, 'ok');
        $trace->recordFallback(new RuntimeException('down'));

        $trace->begin();

        $summary = $trace->summary();
        self::assertSame([], $summary['context']);
        self::assertSame(0, $summary['modelCallCount']);
        self::assertFalse($summary['fellBack']);
        self::assertSame([], $summary['phasesMs']);
    }

    public function test_fallback_message_is_bounded(): void
    {
        $trace = new AssistantTurnTrace();
        $trace->begin();
        $trace->recordFallback(new RuntimeException(str_repeat('x', 1_000)));

        $summary = $trace->summary();
        self::assertTrue($summary['fellBack']);
        self::assertNotNull($summary['fallback']);
        self::assertSame(RuntimeException::class, $summary['fallback']['exception']);
        self::assertSame(200, mb_strlen($summary['fallback']['message']));
    }
}
