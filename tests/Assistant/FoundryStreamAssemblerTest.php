<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryStreamAssembler;

final class FoundryStreamAssemblerTest extends TestCase
{
    public function test_rebuilds_thinking_text_and_tool_blocks_as_the_buffered_response_has_them(): void
    {
        $assembler = new FoundryStreamAssembler();
        foreach (self::toolRound() as [$event, $data]) {
            $assembler->apply($event, $data);
        }

        $message = $assembler->message();

        self::assertSame([
            ['type' => 'thinking', 'thinking' => 'Need the finder.', 'signature' => 'sig-AB'],
            ['type' => 'text', 'text' => 'Let me check.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'find_product', 'input' => ['styleCodes' => ['KL'], 'coreCount' => 4]],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'list_style_options', 'input' => []],
        ], $message['content']);
        self::assertSame('tool_use', $message['stop_reason']);
        self::assertSame(
            ['input_tokens' => 3, 'cache_read_input_tokens' => 5_900, 'output_tokens' => 64],
            $message['usage'],
            'input and cache counts from message_start, the output count from message_delta',
        );
        self::assertNull($assembler->error());
    }

    public function test_text_given_in_the_block_start_and_text_after_thinking_are_both_kept(): void
    {
        $assembler = new FoundryStreamAssembler();
        $assembler->apply('content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]);
        $assembler->apply('content_block_delta', ['index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'hm']]);
        $assembler->apply('content_block_delta', ['index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']]);
        $assembler->apply('content_block_stop', ['index' => 0]);
        $assembler->apply('content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => 'Start, ']]);
        $assembler->apply('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'then more.']]);
        $assembler->apply('content_block_stop', ['index' => 1]);

        $content = $assembler->message()['content'];

        self::assertSame(['thinking', 'text'], array_column($content, 'type'));
        self::assertSame('Start, then more.', $content[1]['text']);
        self::assertSame('sig', $content[0]['signature']);
    }

    public function test_a_refusal_keeps_its_stop_details(): void
    {
        $assembler = new FoundryStreamAssembler();
        $assembler->apply('message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'cyber']],
            'usage' => ['output_tokens' => 0],
        ]);

        self::assertSame('refusal', $assembler->message()['stop_reason']);
        self::assertSame(['type' => 'refusal', 'category' => 'cyber'], $assembler->message()['stop_details']);
    }

    public function test_reports_an_error_event(): void
    {
        $assembler = new FoundryStreamAssembler();
        $assembler->apply('error', ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);

        self::assertSame('overloaded_error: Overloaded', $assembler->error());
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    public static function toolRound(): array
    {
        return [
            ['message_start', ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 3, 'cache_read_input_tokens' => 5_900, 'output_tokens' => 1]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Need the finder.']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig-AB']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Let me ']]],
            ['ping', ['type' => 'ping']],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'check.']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 1]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'find_product', 'input' => []]]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"styleCodes": ["K']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'L"], "coreCount": 4}']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 2]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 3, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'list_style_options', 'input' => []]]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 3]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 64]]],
            ['message_stop', ['type' => 'message_stop']],
        ];
    }
}
