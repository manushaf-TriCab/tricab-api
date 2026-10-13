<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantConversationTurn;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantToolExecutor;
use TriCab\Api\Assistant\Application\AssistantToolResult;
use TriCab\Api\Assistant\Application\AssistantTurnTrace;
use TriCab\Api\Assistant\Infrastructure\Foundry\ClaudeFoundryAgentProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;
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
        self::assertArrayNotHasKey('tools', $secondPayload);
        $secondJson = json_encode($secondPayload, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('tool_call_limit_reached', $secondJson);
        self::assertStringContainsString('"is_error":true', $secondJson);
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

    private function provider(
        FakeJsonHttpClient $http,
        AssistantToolExecutor $tools,
        int $maxToolCalls,
        ?AssistantTurnTrace $trace = null,
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
