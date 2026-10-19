<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\CableFit\Research\FoundryResearchModel;
use TriCab\Core\CableFit\Domain\ResearchModelFailed;

/** The Foundry request shape and its stop-reason handling, against a scripted HTTP client (no network). */
final class FoundryResearchModelTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{status: int, body: string, headers?: array<string, string>}
     */
    private static function reply(array $overrides = [], int $status = 200): array
    {
        $message = $overrides + [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => '{"findings":[]}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'server_tool_use' => ['web_search_requests' => 2]],
        ];

        return ['status' => $status, 'body' => json_encode($message, JSON_THROW_ON_ERROR)];
    }

    /**
     * @param list<array{status: int, body: string, headers?: array<string, string>}|\Throwable> $replies
     */
    private static function http(array $replies): ScriptedHttp
    {
        return new ScriptedHttp($replies);
    }

    private static function model(ScriptedHttp $http, string $authMode = 'api-key', string $key = 'test-key', int $timeout = 300, ?\Closure $clock = null): FoundryResearchModel
    {
        return new FoundryResearchModel(
            $http,
            new class () implements FoundryAccessTokenProvider {
                public function accessToken(): string
                {
                    return 'managed-token';
                }
            },
            'https://foundry.example.test/',
            'claude-sonnet-5',
            $authMode,
            $key,
            $timeout,
            $clock,
            false,
        );
    }

    public function test_the_request_has_the_web_search_tool_with_allowed_domains_and_no_forced_tool(): void
    {
        $http = self::http([self::reply()]);

        $response = self::model($http)->research('SYSTEM', 'USER', ['iec.ch', 'nexans.com'], 6);

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('https://foundry.example.test/anthropic/v1/messages', $request['url']);
        self::assertSame('2023-06-01', $request['headers']['anthropic-version']);
        self::assertSame('test-key', $request['headers']['x-api-key']);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
        $payload = $request['payload'];
        self::assertSame('claude-sonnet-5', $payload['model']);
        self::assertSame('SYSTEM', $payload['system']);
        self::assertSame([['role' => 'user', 'content' => 'USER']], $payload['messages']);
        self::assertSame(
            [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 6, 'allowed_domains' => ['iec.ch', 'nexans.com']]],
            $payload['tools'],
        );
        self::assertArrayNotHasKey('tool_choice', $payload);
        self::assertArrayNotHasKey('temperature', $payload);
        self::assertGreaterThanOrEqual(8000, $payload['max_tokens']);

        self::assertSame([100, 20, 2], [$response->cost->inputTokens, $response->cost->outputTokens, $response->cost->searchRequests]);
        self::assertSame('claude-sonnet-5', $response->cost->model);
        self::assertCount(1, $response->content);
    }

    public function test_managed_identity_sends_a_bearer_token(): void
    {
        $http = self::http([self::reply()]);

        self::model($http, 'managed-identity', '')->research('s', 'u', ['iec.ch'], 3);

        self::assertSame('Bearer managed-token', $http->requests[0]['headers']['Authorization']);
        self::assertArrayNotHasKey('x-api-key', $http->requests[0]['headers']);
    }

    public function test_api_key_mode_without_a_key_fails_before_any_request(): void
    {
        $http = self::http([]);

        try {
            self::model($http, 'api-key', '')->research('s', 'u', ['iec.ch'], 3);
            self::fail('Expected ResearchModelFailed');
        } catch (ResearchModelFailed $failure) {
            self::assertStringContainsString('API key', $failure->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    public function test_pause_turn_continues_the_conversation_and_adds_up_the_cost(): void
    {
        $firstBlocks = [
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'a']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [['type' => 'web_search_result', 'url' => 'https://iec.ch/a', 'title' => 'A', 'page_age' => 'March 11, 2026', 'encrypted_content' => 'enc']]],
        ];
        $http = self::http([
            self::reply(['content' => $firstBlocks, 'stop_reason' => 'pause_turn', 'usage' => ['input_tokens' => 500, 'output_tokens' => 50, 'server_tool_use' => ['web_search_requests' => 1]]]),
            self::reply(['content' => [['type' => 'text', 'text' => '{"findings":[]}']], 'usage' => ['input_tokens' => 700, 'output_tokens' => 30, 'server_tool_use' => ['web_search_requests' => 2]]]),
        ]);

        $response = self::model($http)->research('s', 'u', ['iec.ch'], 8);

        self::assertCount(2, $http->requests);
        /** @var list<array<string, mixed>> $second */
        $second = $http->requests[1]['payload']['messages'];
        self::assertSame(['role' => 'user', 'content' => 'u'], $second[0]);
        self::assertSame(['role' => 'assistant', 'content' => $firstBlocks], $second[1]);
        self::assertCount(2, $second);
        // Content of every turn, in order; cost summed.
        self::assertSame(['server_tool_use', 'web_search_tool_result', 'text'], array_map(static fn (array $b) => $b['type'], $response->content));
        self::assertSame([1200, 80, 3], [$response->cost->inputTokens, $response->cost->outputTokens, $response->cost->searchRequests]);
    }

    public function test_a_model_that_keeps_pausing_is_stopped(): void
    {
        $pause = self::reply(['stop_reason' => 'pause_turn', 'content' => [['type' => 'text', 'text' => '...']]]);
        $http = self::http([$pause, $pause, $pause, $pause, $pause, $pause]);

        try {
            self::model($http)->research('s', 'u', ['iec.ch'], 8);
            self::fail('Expected ResearchModelFailed');
        } catch (ResearchModelFailed $failure) {
            self::assertStringContainsString('did not finish', $failure->getMessage());
        }
        self::assertCount(5, $http->requests);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failedStops(): array
    {
        return [
            'cut off' => ['max_tokens', 'ran out of output space'],
            'refusal' => ['refusal', 'declined'],
            'wants a client tool' => ['tool_use', 'stopped unexpectedly (tool_use)'],
        ];
    }

    #[DataProvider('failedStops')]
    public function test_a_cut_off_a_refusal_or_an_odd_stop_is_a_failure(string $stop, string $message): void
    {
        $http = self::http([self::reply(['stop_reason' => $stop])]);

        $this->expectException(ResearchModelFailed::class);
        $this->expectExceptionMessage($message);

        self::model($http)->research('s', 'u', ['iec.ch'], 8);
    }

    public function test_an_http_error_is_a_failure_that_carries_the_service_message_but_nothing_else(): void
    {
        $http = self::http([['status' => 400, 'body' => json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'web_search_20260209 is not supported']], JSON_THROW_ON_ERROR)]]);

        try {
            self::model($http)->research('s', 'u', ['iec.ch'], 8);
            self::fail('Expected ResearchModelFailed');
        } catch (ResearchModelFailed $failure) {
            self::assertSame('The model refused the request (HTTP 400: web_search_20260209 is not supported).', $failure->getMessage());
            self::assertStringNotContainsString('test-key', $failure->getMessage());
        }
    }

    public function test_a_transport_error_is_a_failure(): void
    {
        $http = self::http([new RuntimeException('connection reset to https://foundry.example.test with key test-key')]);

        try {
            self::model($http)->research('s', 'u', ['iec.ch'], 8);
            self::fail('Expected ResearchModelFailed');
        } catch (ResearchModelFailed $failure) {
            self::assertStringContainsString('could not be reached', $failure->getMessage());
            self::assertStringNotContainsString('test-key', $failure->getMessage());
        }
    }

    public function test_a_body_that_is_not_json_is_a_failure(): void
    {
        $http = self::http([['status' => 200, 'body' => '<html>gateway</html>']]);

        $this->expectException(ResearchModelFailed::class);
        $this->expectExceptionMessage('not JSON');

        self::model($http)->research('s', 'u', ['iec.ch'], 8);
    }

    public function test_a_throttled_request_is_retried_once(): void
    {
        $http = self::http([['status' => 429, 'body' => '{}', 'headers' => ['retry-after' => '1']], self::reply()]);

        $response = self::model($http)->research('s', 'u', ['iec.ch'], 8);

        self::assertCount(2, $http->requests);
        self::assertSame(100, $response->cost->inputTokens);

        $twice = self::http([['status' => 529, 'body' => '{}'], ['status' => 529, 'body' => '{}']]);
        $this->expectException(ResearchModelFailed::class);
        self::model($twice)->research('s', 'u', ['iec.ch'], 8);
    }

    public function test_the_whole_exchange_has_a_time_budget(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $pause = self::reply(['stop_reason' => 'pause_turn', 'content' => [['type' => 'text', 'text' => '...']]]);
        $http = self::http([$pause, $pause, $pause]);
        $http->afterEachRequest = static function () use (&$now): void {
            $now += 200.0;
        };

        try {
            self::model($http, 'api-key', 'test-key', 300, $clock)->research('s', 'u', ['iec.ch'], 8);
            self::fail('Expected ResearchModelFailed');
        } catch (ResearchModelFailed $failure) {
            self::assertStringContainsString('longer than 300 seconds', $failure->getMessage());
        }
        self::assertCount(2, $http->requests);
    }
}
