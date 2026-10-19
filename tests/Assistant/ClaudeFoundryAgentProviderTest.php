<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantConversationTurn;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantReplySpec;
use TriCab\Api\Assistant\Application\AssistantToolExecutor;
use TriCab\Api\Assistant\Application\AssistantToolResult;
use TriCab\Api\Assistant\Application\AssistantTurnEvents;
use TriCab\Api\Assistant\Application\AssistantTurnTrace;
use TriCab\Api\Assistant\Infrastructure\Foundry\ClaudeFoundryAgentProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryStreamClient;
use TriCab\Api\Assistant\Infrastructure\Prompt\VersionedPrompt;
use TriCab\Api\Config\Settings;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

final class ClaudeFoundryAgentProviderTest extends TestCase
{
    public function test_executes_a_tool_then_returns_the_final_answer_with_history(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => json_encode([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'tool-1',
                    'name' => 'search_product_codes',
                    'input' => ['styleCodes' => ['KL'], 'coreCount' => 4],
                ]],
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"KL-PAXN/4C16OR exists, but stock is not live-confirmed."}]}'],
        ];
        $tools = $this->toolExecutor();
        $provider = $this->provider($http, $tools, 5);

        $result = $provider->generate(new AssistantGrounding(
            userMessage: 'No, make that 16 mm2.',
            searchQuery: 'KL 4 core 16 mm2',
            regionCode: 'AU',
            products: [],
            catalogueVisibility: 'staff',
            conversationTurns: [new AssistantConversationTurn(
                userMessage: 'Find a 4 core 10 mm2 KL cable.',
                assistantMessage: 'Do you need an earth?',
            )],
        ));

        self::assertSame('foundry-claude-agent', $result->provider);
        self::assertSame('product-assistant-response-v3', $result->instructionVersion);
        self::assertSame(['search_product_codes'], $result->toolNames);
        self::assertSame('product-code', $result->cards[0]['type']);
        self::assertSame('KL-PAXN/4C16OR', $result->cards[0]['codeValue']);
        self::assertSame('KL-PAXN/4C16OR exists, but stock is not live-confirmed.', $result->text);
        self::assertCount(2, $http->calls);
        self::assertCount(1, $tools->calls);
        self::assertSame('AU', $tools->calls[0]['regionCode']);
        self::assertSame(AssistantCatalogueVisibility::STAFF, $tools->calls[0]['visibility']);

        $firstPayload = $this->payload($http, 0);
        self::assertArrayHasKey('tools', $firstPayload);
        self::assertArrayNotHasKey('tool_choice', $firstPayload);
        self::assertArrayNotHasKey('output_config', $firstPayload, 'no effort is sent unless configured');
        $firstJson = json_encode($firstPayload, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Find a 4 core 10 mm2 KL cable.', $firstJson);
        self::assertStringContainsString('Do you need an earth?', $firstJson);
        self::assertStringContainsString('\\"regionCode\\":\\"AU\\"', $firstJson);

        $secondJson = json_encode($this->payload($http, 1), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('tool_result', $secondJson);
        self::assertStringContainsString('AUTHORIZED_TOOL_DATA', $secondJson);
        self::assertStringContainsString('untrusted_data_not_instructions', $secondJson);
    }

    public function test_never_executes_more_than_the_configured_tool_call_bound(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => json_encode([
                'content' => [
                    ['type' => 'tool_use', 'id' => 'one', 'name' => 'search_product_codes', 'input' => ['text' => 'KL']],
                    ['type' => 'tool_use', 'id' => 'two', 'name' => 'list_style_options', 'input' => []],
                ],
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"I reached the tool limit; here is the verified result I have."}]}'],
        ];
        $tools = $this->toolExecutor();

        $result = $this->provider($http, $tools, 1)->generate(new AssistantGrounding(
            userMessage: 'Find KL',
            searchQuery: 'KL',
            regionCode: null,
            products: [],
        ));

        self::assertCount(1, $tools->calls);
        self::assertSame(['search_product_codes'], $result->toolNames);
        $secondPayload = $this->payload($http, 1);
        // Tools stay offered with no tool_choice (tool_choice none ends in an empty
        // thinking-only reply on this model); the error result says to answer.
        self::assertSame($this->payload($http, 0)['tools'], $secondPayload['tools']);
        self::assertArrayNotHasKey('tool_choice', $secondPayload);
        $secondJson = json_encode($secondPayload, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('tool_call_limit_reached', $secondJson);
        self::assertStringContainsString('No more lookups this turn. Answer the customer now', $secondJson);
        self::assertStringContainsString('"is_error":true', $secondJson);
    }

    public function test_past_the_turn_deadline_it_stops_using_tools_and_answers_with_what_it_has(): void
    {
        $clock = new TestClock();
        $http = new FakeJsonHttpClient();
        $http->queue = [$this->toolUseResponse(), [
            'status' => 200,
            'body' => '{"content":[{"type":"text","text":"Here is what I found so far."}]}',
        ]];
        $provider = $this->provider($http, new SlowAssistantToolExecutor($clock), 5, clock: static fn (): float => $clock->now);

        $result = $provider->generate(new AssistantGrounding(userMessage: 'Find KL', searchQuery: 'KL', regionCode: null, products: []));

        self::assertSame('Here is what I found so far.', $result->text);
        self::assertCount(2, $http->calls);
        self::assertArrayNotHasKey('tool_choice', $this->payload($http, 0));
        self::assertArrayNotHasKey('tool_choice', $this->payload($http, 1));
    }

    public function test_past_the_turn_deadline_a_model_that_keeps_asking_for_tools_gets_two_finishing_rounds_then_a_tool_free_last_call(): void
    {
        $clock = new TestClock();
        $http = new FakeJsonHttpClient();
        $http->queue = [$this->toolUseResponse(), $this->toolUseResponse(), $this->toolUseResponse(), $this->toolUseResponse(), $this->toolUseResponse()];
        $tools = new SlowAssistantToolExecutor($clock);
        $trace = new AssistantTurnTrace();
        $trace->begin();
        $provider = $this->provider($http, $tools, 5, $trace, clock: static fn (): float => $clock->now);

        try {
            $provider->generate(new AssistantGrounding(userMessage: 'Find KL', searchQuery: 'KL', regionCode: null, products: []));
            self::fail('Expected the turn to end unavailable.');
        } catch (FoundryProviderUnavailable $exception) {
            self::assertStringContainsString('turn deadline', $exception->getMessage());
        }
        self::assertCount(4, $http->calls, 'one tool round, two finishing rounds, one tool-free last call, then no more');
        self::assertSame(1, $tools->executed, 'the deadline stops new tool executions');
        self::assertArrayNotHasKey('tool_choice', $this->payload($http, 1));
        self::assertArrayNotHasKey('tool_choice', $this->payload($http, 2));
        self::assertSame(['type' => 'none'], $this->payload($http, 3)['tool_choice']);
        self::assertStringContainsString('turn_deadline_reached', json_encode($this->payload($http, 2), JSON_THROW_ON_ERROR));
        self::assertSame(2, $trace->summary()['context']['finishingRounds']);
    }

    public function test_a_tool_free_last_call_with_no_text_is_unavailable(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [$this->toolUseResponse(), $this->toolUseResponse(), $this->toolUseResponse(), ['status' => 200, 'body' => '{"content":[{"type":"thinking","thinking":"x"}]}']];

        try {
            $this->provider($http, $this->toolExecutor(), 1)->generate($this->grounding());
            self::fail('Expected unavailable.');
        } catch (FoundryProviderUnavailable $exception) {
            self::assertSame('Foundry Messages response did not contain text.', $exception->getMessage());
        }
        self::assertCount(4, $http->calls);
        self::assertSame(['type' => 'none'], $this->payload($http, 3)['tool_choice']);
    }

    /** @return array{status: int, body: string} */
    private function toolUseResponse(): array
    {
        return ['status' => 200, 'body' => json_encode([
            'content' => [['type' => 'tool_use', 'id' => 'one', 'name' => 'search_product_codes', 'input' => ['text' => 'KL']]],
        ], JSON_THROW_ON_ERROR)];
    }

    public function test_a_researched_code_is_not_offered_when_the_answer_is_about_something_else(): void
    {
        // The agent looks a style up to describe it, then recommends a
        // different one. The looked-up code is working, not an offer: it must
        // not arrive as a product card under a recommendation to buy something
        // else. `curatesCards` then stops `ProductAssistantService` refilling
        // the empty set from its pre-search.
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => json_encode([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'tool-1',
                    'name' => 'search_product_codes',
                    'input' => ['styleCodes' => ['KL']],
                ]],
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => json_encode([
                'content' => [[
                    'type' => 'text',
                    'text' => '`KL` is not LSZH. The closest equivalent is `CC` — Flexible LSZH (Fixed Wiring).',
                ]],
            ], JSON_THROW_ON_ERROR)],
        ];
        $tools = $this->toolExecutor();

        $result = $this->provider($http, $tools, 5)->generate(new AssistantGrounding(
            userMessage: 'Do you have a low smoke cable style similar to KL?',
            searchQuery: 'low smoke cable similar to KL',
            regionCode: 'AU',
            products: [],
        ));

        self::assertCount(1, $tools->calls, 'the lookup still happens');
        self::assertSame([], $result->cards, 'but it is not offered to the customer');
        self::assertTrue($result->curatesCards);
    }

    public function test_marks_the_shared_prefix_and_the_conversation_for_caching(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"Answer."}]}'],
        ];

        $this->provider($http, $this->toolExecutor(), 5, effort: 'low')->generate(new AssistantGrounding(
            userMessage: 'KL',
            searchQuery: 'KL',
            regionCode: 'AU',
            products: [],
        ));

        $payload = $this->payload($http, 0);
        self::assertSame(['type' => 'ephemeral'], $payload['cache_control']);
        self::assertSame([[
            'type' => 'text',
            'text' => 'Use bounded server tools.',
            'cache_control' => ['type' => 'ephemeral'],
        ]], $payload['system']);
        self::assertSame(['effort' => 'low'], $payload['output_config']);
        self::assertSame(8_000, $payload['max_tokens']);
    }

    public function test_a_refusal_makes_the_provider_unavailable_instead_of_answering(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => json_encode([
                'content' => [['type' => 'text', 'text' => 'Partial text before the decline']],
                'stop_reason' => 'refusal',
                'stop_details' => ['type' => 'refusal', 'category' => 'general_harms', 'explanation' => null],
            ], JSON_THROW_ON_ERROR)],
        ];
        $trace = new AssistantTurnTrace();
        $trace->begin();

        try {
            $this->provider($http, $this->toolExecutor(), 5, $trace)->generate(new AssistantGrounding(
                userMessage: 'KL',
                searchQuery: 'KL',
                regionCode: 'AU',
                products: [],
            ));
            self::fail('A refusal must not be returned as an answer.');
        } catch (FoundryProviderUnavailable $exception) {
            self::assertStringContainsString('refusal: general_harms', $exception->getMessage());
        }

        self::assertSame('refusal', $trace->summary()['modelCalls'][0]['stopReason']);
    }

    public function test_streams_text_and_tool_progress_to_a_listener_and_answers_the_same_way(): void
    {
        $answer = [
            ['message_start', ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 2, 'cache_read_input_tokens' => 6_100]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'KL-PAXN/4C16OR ']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'exists.']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 9]]],
            ['message_stop', ['type' => 'message_stop']],
        ];
        $stream = new ScriptedFoundryStreamClient([FoundryStreamAssemblerTest::toolRound(), $answer]);
        $http = new FakeJsonHttpClient();
        $events = new AssistantTurnEvents();
        $heard = [];
        $events->listen(static function (string $event, array $data) use (&$heard): void {
            $heard[] = [$event, $data];
        });
        $trace = new AssistantTurnTrace();
        $trace->begin();

        $result = $this->provider($http, $this->toolExecutor(), 5, $trace, stream: $stream, events: $events)
            ->generate(new AssistantGrounding(userMessage: 'KL 4 core', searchQuery: 'KL 4 core', regionCode: 'AU', products: []));

        self::assertSame('KL-PAXN/4C16OR exists.', $result->text);
        self::assertSame(['product-code'], array_column($result->cards, 'type'));
        self::assertSame([
            ['text', ['text' => 'Let me ']],
            ['text', ['text' => 'check.']],
            ['tool', ['name' => 'find_product']],
            ['tool', ['name' => 'list_style_options']],
            ['text', ['text' => 'KL-PAXN/4C16OR ']],
            ['text', ['text' => 'exists.']],
        ], $heard);
        self::assertSame([], $http->calls, 'nothing goes the buffered way while someone listens');
        self::assertCount(2, $stream->payloads);
        self::assertTrue($stream->payloads[0]['stream']);

        // The tool round goes back exactly as it came, thinking signature included,
        // and the argument-less call as an object, not a JSON array.
        $resent = json_encode($stream->payloads[1]['messages'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"signature":"sig-AB"', $resent);
        self::assertStringContainsString('"name":"list_style_options","input":{}', $resent);

        $summary = $trace->summary();
        self::assertTrue($summary['context']['streamed']);
        self::assertSame(['tool_use', 'end_turn'], array_column($summary['modelCalls'], 'stopReason'));
        self::assertSame(6_100, $summary['modelCalls'][1]['usage']['cache_read_input_tokens']);
    }

    public function test_a_stream_rejected_by_foundry_makes_the_provider_unavailable(): void
    {
        $stream = new ScriptedFoundryStreamClient([], status: 529, body: '{"type":"error","error":{"type":"overloaded_error"}}');
        $events = new AssistantTurnEvents();
        $events->listen(static function (): void {
        });

        $this->expectException(FoundryProviderUnavailable::class);
        $this->expectExceptionMessage('HTTP 529');
        $this->provider(new FakeJsonHttpClient(), $this->toolExecutor(), 5, stream: $stream, events: $events)
            ->generate(new AssistantGrounding(userMessage: 'KL', searchQuery: 'KL', regionCode: 'AU', products: []));
    }

    public function test_traces_each_round_trip_and_tool_call(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => json_encode([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'tool-1',
                    'name' => 'search_product_codes',
                    'input' => ['styleCodes' => ['KL']],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 2_400, 'output_tokens' => 60],
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => json_encode([
                'content' => [['type' => 'text', 'text' => 'KL-PAXN/4C16OR exists.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 2_700, 'output_tokens' => 30],
            ], JSON_THROW_ON_ERROR)],
        ];
        $trace = new AssistantTurnTrace();
        $trace->begin();

        $this->provider($http, $this->toolExecutor(), 5, $trace)->generate(new AssistantGrounding(
            userMessage: 'KL 4 core 16',
            searchQuery: 'KL 4 core 16',
            regionCode: 'AU',
            products: [],
        ));

        $summary = $trace->summary();
        self::assertSame('claude-sonnet-5', $summary['context']['model']);
        self::assertSame(2, $summary['modelCallCount']);
        self::assertSame(['tool_use', 'end_turn'], array_column($summary['modelCalls'], 'stopReason'));
        self::assertSame(['ok', 'ok'], array_column($summary['modelCalls'], 'outcome'));
        self::assertSame(['input_tokens' => 2_700, 'output_tokens' => 30], $summary['modelCalls'][1]['usage']);
        self::assertSame([['name' => 'search_product_codes', 'ms' => $summary['toolCalls'][0]['ms'], 'ok' => true]], $summary['toolCalls']);
        self::assertArrayHasKey('foundry_token', $summary['phasesMs']);
    }

    public function test_traces_a_rejected_round_trip_before_failing(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 429, 'body' => '{"error":{"message":"Rate limit"}}'],
        ];
        $trace = new AssistantTurnTrace();
        $trace->begin();

        try {
            $this->provider($http, $this->toolExecutor(), 5, $trace)->generate(new AssistantGrounding(
                userMessage: 'KL',
                searchQuery: 'KL',
                regionCode: 'AU',
                products: [],
            ));
            self::fail('A rejected request must make the provider unavailable.');
        } catch (FoundryProviderUnavailable) {
        }

        self::assertSame('http_429', $trace->summary()['modelCalls'][0]['outcome']);
    }

    public function test_the_reply_spec_is_the_last_finder_call_of_the_turn(): void
    {
        $call = static fn (string $id, string $name, array $input): array => ['status' => 200, 'body' => json_encode([
            'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
        ], JSON_THROW_ON_ERROR)];
        $http = new FakeJsonHttpClient();
        $http->queue = [
            $call('t1', 'find_product', ['style' => 'XL', 'coreCount' => 3]),
            $call('t2', 'search_product_codes', ['coreCount' => 9]),
            $call('t3', 'find_product', ['style' => 'XL', 'coreCount' => 4, 'crossSectionMm2' => 16]),
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"Done."}]}'],
        ];
        $provider = $this->provider($http, new SpecEchoingToolExecutor(), 5);

        $result = $provider->generate($this->grounding());

        self::assertIsArray($result->spec);
        self::assertSame(4, $result->spec['coreCount']);
        self::assertEquals(16, $result->spec['crossSectionMm2']);
    }

    public function test_a_turn_without_a_finder_call_has_no_spec(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [['status' => 200, 'body' => '{"content":[{"type":"text","text":"Hello."}]}']];

        $result = $this->provider($http, new SpecEchoingToolExecutor(), 5)->generate($this->grounding());

        self::assertNull($result->spec);
    }

    public function test_a_code_search_spec_stands_in_when_no_finder_call_ran(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => json_encode(['content' => [[
                'type' => 'tool_use', 'id' => 't1', 'name' => 'search_product_codes', 'input' => ['coreCount' => 9],
            ]]], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"Done."}]}'],
        ];

        $result = $this->provider($http, new SpecEchoingToolExecutor(), 5)->generate($this->grounding());

        self::assertIsArray($result->spec);
        self::assertSame(9, $result->spec['coreCount']);
    }

    public function test_an_empty_final_is_recovered_with_one_retry_that_still_offers_tools(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            $this->toolUseResponse(),
            ['status' => 200, 'body' => json_encode([
                'content' => [['type' => 'thinking', 'thinking' => 'secret reasoning', 'signature' => 'sig']],
                'stop_reason' => 'end_turn',
                'usage' => ['output_tokens' => 29],
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"KL suits that."}],"stop_reason":"end_turn"}'],
        ];
        $trace = new AssistantTurnTrace();
        $trace->begin();

        $result = $this->provider($http, $this->toolExecutor(), 1, $trace)->generate($this->grounding());

        self::assertSame('KL suits that.', $result->text);
        self::assertCount(3, $http->calls);
        $recovery = $this->payload($http, 2);
        self::assertArrayNotHasKey('tool_choice', $recovery);
        self::assertSame($this->payload($http, 0)['tools'], $recovery['tools']);
        $messages = $recovery['messages'];
        self::assertIsArray($messages);
        /** @var list<array{role: string, content: mixed}> $messages */
        $last = $messages[count($messages) - 1];
        self::assertSame('user', $last['role']);
        self::assertIsString($last['content']);
        self::assertStringContainsString('plain text', $last['content']);
        self::assertStringNotContainsString('secret reasoning', json_encode($recovery, JSON_THROW_ON_ERROR), 'the empty turn is not resent');
        self::assertSame('user', $messages[count($messages) - 2]['role']);
        $summary = $trace->summary();
        self::assertTrue($summary['context']['emptyFinalRecovered']);
        self::assertSame('thinking', $summary['context']['emptyFinalBlockTypes']);
        self::assertSame('end_turn', $summary['context']['emptyFinalStopReason']);
        self::assertStringNotContainsString('secret reasoning', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function test_an_empty_final_twice_is_unavailable_as_before(): void
    {
        $empty = ['status' => 200, 'body' => '{"content":[{"type":"text","text":"  "}],"stop_reason":"end_turn"}'];
        $http = new FakeJsonHttpClient();
        $http->queue = [$this->toolUseResponse(), $empty, $empty, $empty];
        $trace = new AssistantTurnTrace();
        $trace->begin();

        try {
            $this->provider($http, $this->toolExecutor(), 5, $trace)->generate($this->grounding());
            self::fail('Expected the turn to end unavailable.');
        } catch (FoundryProviderUnavailable $exception) {
            self::assertSame('Foundry Messages response did not contain text.', $exception->getMessage());
        }
        self::assertCount(3, $http->calls, 'one retry, no more');
        $summary = $trace->summary();
        self::assertFalse($summary['context']['emptyFinalRecovered']);
        self::assertSame('text', $summary['context']['emptyFinalBlockTypes']);
    }

    public function test_an_empty_final_past_the_deadline_still_gets_its_one_recovery_call(): void
    {
        $clock = new TestClock();
        $http = new FakeJsonHttpClient();
        $http->queue = [
            $this->toolUseResponse(),
            ['status' => 200, 'body' => '{"content":[],"stop_reason":"end_turn"}'],
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"Answer."}]}'],
        ];
        $provider = $this->provider($http, new SlowAssistantToolExecutor($clock), 5, clock: static fn (): float => $clock->now);

        self::assertSame('Answer.', $provider->generate($this->grounding())->text);
    }

    public function test_a_tool_call_in_the_recovery_retry_is_answered_with_the_instruction_and_not_run(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => '{"content":[{"type":"thinking","thinking":"x"}],"stop_reason":"end_turn"}'],
            $this->toolUseResponse(),
            ['status' => 200, 'body' => '{"content":[{"type":"text","text":"Done."}]}'],
        ];
        $tools = $this->toolExecutor();

        $result = $this->provider($http, $tools, 5)->generate($this->grounding());

        self::assertSame('Done.', $result->text);
        self::assertSame([], $tools->calls);
        self::assertStringContainsString('No more lookups this turn', json_encode($this->payload($http, 2), JSON_THROW_ON_ERROR));
    }

    public function test_a_failure_after_a_finder_call_carries_the_spec_on_the_exception(): void
    {
        $call = ['status' => 200, 'body' => json_encode([
            'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'find_product', 'input' => ['coreCount' => 4]]],
        ], JSON_THROW_ON_ERROR)];
        $empty = ['status' => 200, 'body' => '{"content":[]}'];
        $http = new FakeJsonHttpClient();
        $http->queue = [$call, $empty, $empty];

        try {
            $this->provider($http, new SpecEchoingToolExecutor(), 5)->generate($this->grounding());
            self::fail('Expected unavailable.');
        } catch (FoundryProviderUnavailable $exception) {
            self::assertSame(4, $exception->partialSpec()['coreCount'] ?? null);
        }
    }

    public function test_a_stream_with_thinking_only_then_text_recovers_through_the_stream(): void
    {
        $stream = new ScriptedFoundryStreamClient([
            [
                ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']]],
                ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'hmm']]],
                ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 29]]],
            ],
            [
                ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => 'Hel']]],
                ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'lo.']]],
                ['message_delta', ['delta' => ['stop_reason' => 'end_turn']]],
            ],
        ]);
        $events = new AssistantTurnEvents();
        $events->listen(static function (): void {
        });

        $result = $this->provider(new FakeJsonHttpClient(), $this->toolExecutor(), 5, stream: $stream, events: $events)->generate($this->grounding());

        self::assertSame('Hello.', $result->text);
        self::assertArrayNotHasKey('tool_choice', $stream->payloads[1]);
    }

    private function grounding(): AssistantGrounding
    {
        return new AssistantGrounding(
            userMessage: 'Need a cable',
            searchQuery: 'cable',
            regionCode: 'AU',
            products: [],
            catalogueVisibility: 'public',
            conversationTurns: [],
        );
    }

    private function provider(
        FakeJsonHttpClient $http,
        AssistantToolExecutor $tools,
        int $maxToolCalls,
        ?AssistantTurnTrace $trace = null,
        string $effort = '',
        ?FoundryStreamClient $stream = null,
        ?AssistantTurnEvents $events = null,
        ?\Closure $clock = null,
    ): ClaudeFoundryAgentProvider {
        return new ClaudeFoundryAgentProvider(
            http: $http,
            accessTokens: new class implements FoundryAccessTokenProvider {
                public function accessToken(): string
                {
                    throw new \LogicException('API-key test must not request a token.');
                }
            },
            tools: $tools,
            endpoint: 'https://tricab-ai2.services.ai.azure.com',
            deployment: 'claude-sonnet-5',
            authMode: Settings::FOUNDRY_AUTH_MODE_API_KEY,
            prompt: new VersionedPrompt(
                name: 'product-assistant-response',
                version: 'v3',
                content: 'Use bounded server tools.',
            ),
            maxToolCalls: $maxToolCalls,
            apiKey: 'test-key',
            trace: $trace ?? new AssistantTurnTrace(),
            effort: $effort,
            stream: $stream,
            events: $events ?? new AssistantTurnEvents(),
            turnDeadlineSeconds: 45,
            clock: $clock,
        );
    }

    private function toolExecutor(): RecordingAssistantToolExecutor
    {
        return new RecordingAssistantToolExecutor();
    }

    /** @return array<string, mixed> */
    private function payload(FakeJsonHttpClient $http, int $index): array
    {
        $payload = $http->calls[$index]['payload'] ?? null;
        if (!is_array($payload)) {
            self::fail('Expected a JSON request payload.');
        }

        return $payload;
    }
}

final class RecordingAssistantToolExecutor implements AssistantToolExecutor
{
    /** @var list<array{name: string, input: array<string, mixed>, regionCode: ?string, visibility: AssistantCatalogueVisibility}> */
    public array $calls = [];

    public function definitions(): array
    {
        return [[
            'name' => 'search_product_codes',
            'description' => 'Search codes.',
            'input_schema' => ['type' => 'object', 'properties' => []],
        ]];
    }

    public function execute(
        string $name,
        array $input,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): AssistantToolResult {
        $this->calls[] = [
            'name' => $name,
            'input' => $input,
            'regionCode' => $regionCode,
            'visibility' => $catalogueVisibility,
        ];

        return new AssistantToolResult(
            data: ['items' => [['codeValue' => 'KL-PAXN/4C16OR']]],
            cards: [[
                'type' => 'product-code',
                'codeValue' => 'KL-PAXN/4C16OR',
                'segments' => ['styleCode' => 'KL', 'coreCount' => 4, 'crossSection' => '16'],
                'stockStatus' => null,
            ]],
        );
    }
}

final class ScriptedFoundryStreamClient implements FoundryStreamClient
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    /** @param list<list<array{0: string, 1: array<string, mixed>}>> $rounds one event list per call */
    public function __construct(
        private array $rounds,
        private readonly int $status = 200,
        private readonly string $body = '',
    ) {
    }

    public function stream(string $url, array $headers, array $payload, callable $onEvent): array
    {
        $this->payloads[] = $payload;
        if ($this->status !== 200) {
            return ['status' => $this->status, 'body' => $this->body];
        }
        foreach (array_shift($this->rounds) ?? [] as [$event, $data]) {
            $onEvent($event, $data);
        }

        return ['status' => 200, 'body' => ''];
    }
}

/** A tool that uses up the turn's whole budget, as a slow catalogue query would. */
final class SlowAssistantToolExecutor implements AssistantToolExecutor
{
    public int $executed = 0;

    public function __construct(private readonly TestClock $clock)
    {
    }

    public function definitions(): array
    {
        return (new RecordingAssistantToolExecutor())->definitions();
    }

    public function execute(
        string $name,
        array $input,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): AssistantToolResult {
        $this->executed++;
        $this->clock->now += 50.0;

        return new AssistantToolResult(data: ['items' => []], cards: []);
    }
}

final class TestClock
{
    public float $now = 0.0;
}

/** Answers every call with a spec built from its own input, as the real executor does. */
final class SpecEchoingToolExecutor implements AssistantToolExecutor
{
    public function definitions(): array
    {
        return [['name' => 'find_product', 'description' => 'x', 'input_schema' => ['type' => 'object', 'properties' => []]]];
    }

    public function execute(
        string $name,
        array $input,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): AssistantToolResult {
        $spec = AssistantReplySpec::build(
            coreCount: is_int($input['coreCount'] ?? null) ? $input['coreCount'] : null,
            crossSectionMm2: is_int($input['crossSectionMm2'] ?? null) || is_float($input['crossSectionMm2'] ?? null) ? (float) $input['crossSectionMm2'] : null,
        );

        return new AssistantToolResult(data: ['ok' => true], spec: $spec);
    }
}
