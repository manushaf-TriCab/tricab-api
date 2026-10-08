<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ai;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Ai\HttpFinanceAiClient;
use TriCab\Api\Finance\Ai\UndeployedFinanceAiClient;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiContract;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/**
 * The PHP client against a fake HTTP client: no service, no network, and no real token
 * (the one below is a dummy).
 */
final class HttpFinanceAiClientTest extends TestCase
{
    private const TOKEN = 'dummy-internal-token';

    private FakeJsonHttpClient $http;

    private float $now = 100.0;

    protected function setUp(): void
    {
        $this->http = new FakeJsonHttpClient();
    }

    private function client(): HttpFinanceAiClient
    {
        return new HttpFinanceAiClient($this->http, 'http://127.0.0.1:8003/', self::TOKEN, fn (): float => $this->now);
    }

    public function test_it_posts_the_purpose_and_the_data_only_with_the_contract_headers_and_the_token(): void
    {
        $http = new EchoingFakeHttp(['result' => ['vendor' => 'Acme'], 'provider' => ['name' => 'openrouter', 'model' => 'm']]);
        $client = new HttpFinanceAiClient($http, 'http://127.0.0.1:8003/', self::TOKEN);

        $result = $client->complete('invoice-extract', ['text' => 'hello'], 30000);

        $call = $http->calls[0];
        self::assertSame('http://127.0.0.1:8003/v1/complete/invoice-extract', $call['url']);
        self::assertSame('Bearer ' . self::TOKEN, $call['headers']['Authorization']);
        self::assertSame('finance-ai.v1', $call['headers']['X-Finance-Ai-Contract']);
        self::assertMatchesRegularExpression(FinanceAiContract::CORRELATION_PATTERN, $call['headers']['X-Correlation-Id']);
        $payload = $call['payload'];
        self::assertIsArray($payload);
        self::assertSame(['contract', 'correlationId', 'deadlineMs', 'data'], array_keys($payload));
        self::assertSame(['text' => 'hello'], $payload['data']);
        self::assertSame(30000, $payload['deadlineMs']);
        // Never a prompt: the caller names a purpose and sends data.
        self::assertArrayNotHasKey('prompt', $payload);

        self::assertSame('invoice-extract', $result->purpose);
        self::assertSame(['vendor' => 'Acme'], $result->result);
        self::assertSame(['openrouter', 'm'], [$result->provider, $result->model]);
        self::assertSame($call['headers']['X-Correlation-Id'], $result->correlationId);
    }

    public function test_the_default_deadline_applies_and_the_bounds_are_enforced_before_any_call(): void
    {
        $http = new EchoingFakeHttp(['result' => [], 'provider' => ['name' => 'openrouter', 'model' => 'm']]);
        $client = new HttpFinanceAiClient($http, 'http://127.0.0.1:8003', self::TOKEN);

        $client->complete('p', []);
        self::assertSame(FinanceAiContract::DEFAULT_DEADLINE_MS, $http->calls[0]['payload']['deadlineMs'] ?? null);

        foreach ([999, 200001] as $bad) {
            $this->expectFailure(FinanceAiFailure::INVALID_REQUEST, fn () => $client->complete('p', [], $bad));
        }
        foreach (['', 'Has Space', '../etc', 'UPPER'] as $name) {
            $this->expectFailure(FinanceAiFailure::INVALID_REQUEST, fn () => $client->complete($name, []));
        }
        self::assertCount(1, $http->calls);
    }

    public function test_empty_data_is_sent_as_an_object_not_a_list(): void
    {
        $http = new EchoingFakeHttp(['result' => [], 'provider' => ['name' => 'openrouter', 'model' => 'm']]);

        (new HttpFinanceAiClient($http, 'http://127.0.0.1:8003', self::TOKEN))->complete('p', []);

        self::assertEquals(new \stdClass(), $http->calls[0]['payload']['data'] ?? null);
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>, string}>
     */
    public static function serviceErrors(): iterable
    {
        foreach (\TriCab\Core\Finance\Shared\Ai\FinanceAiFailure::CODES as $code) {
            yield $code => [$code === 'unknown_purpose' ? 404 : 502, ['error' => ['code' => $code, 'message' => 'Safe to show.']], $code];
        }
        yield 'a code this side does not know' => [500, ['error' => ['code' => 'brand_new', 'message' => 'Safe to show.']], FinanceAiFailure::INTERNAL_ERROR];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('serviceErrors')]
    public function test_a_service_error_becomes_the_typed_failure_with_the_service_message(int $status, array $body, string $code): void
    {
        $this->http->queue = [['status' => $status, 'body' => json_encode(['contract' => 'finance-ai.v1', 'correlationId' => 'x'] + $body, JSON_THROW_ON_ERROR)]];

        try {
            $this->client()->complete('p', ['a' => 1]);
            self::fail('Expected a failure.');
        } catch (FinanceAiFailure $failure) {
            self::assertSame($code, $failure->failureCode);
            self::assertSame('Safe to show.', $failure->getMessage());
            self::assertNotNull($failure->correlationId);
        }
    }

    public function test_only_unavailable_timeout_and_provider_unavailable_are_retryable(): void
    {
        $retryable = array_values(array_filter(
            FinanceAiFailure::CODES,
            static fn (string $code): bool => FinanceAiFailure::of($code, 'm')->isRetryable(),
        ));

        self::assertSame(['unavailable', 'timeout', 'provider_unavailable'], $retryable);
    }

    public function test_an_unreachable_service_is_unavailable_and_a_slow_one_is_a_timeout(): void
    {
        $this->http->transportError = new RuntimeException('connection refused');
        $this->expectFailure(FinanceAiFailure::UNAVAILABLE, fn () => $this->client()->complete('p', [], 5000));

        // Same exception, but the clock says the whole deadline passed.
        $ticks = [100.0, 106.0];
        $slow = new HttpFinanceAiClient($this->http, 'http://127.0.0.1:8003', self::TOKEN, static function () use (&$ticks): float {
            return array_shift($ticks) ?? 106.0;
        });
        $this->expectFailure(FinanceAiFailure::TIMEOUT, fn () => $slow->complete('p', [], 5000));
    }

    public function test_a_refused_token_is_a_deployment_fault_and_says_nothing_of_it(): void
    {
        $this->http->queue = [['status' => 401, 'body' => '{"contract":"finance-ai.v1","error":{"code":"invalid_request","message":"no"}}']];

        try {
            $this->client()->complete('p', []);
            self::fail('Expected a failure.');
        } catch (FinanceAiFailure $failure) {
            self::assertSame(FinanceAiFailure::INTERNAL_ERROR, $failure->failureCode);
            self::assertSame('The finance AI service is misconfigured.', $failure->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $failure->getMessage());
        }
    }

    public function test_an_unreadable_body_is_unavailable_for_a_502_or_503_and_internal_otherwise(): void
    {
        foreach ([502 => FinanceAiFailure::UNAVAILABLE, 503 => FinanceAiFailure::UNAVAILABLE, 200 => FinanceAiFailure::INTERNAL_ERROR, 500 => FinanceAiFailure::INTERNAL_ERROR] as $status => $code) {
            $this->http->queue = [['status' => $status, 'body' => '<html>bad gateway</html>']];
            $this->expectFailure($code, fn () => $this->client()->complete('p', []));
        }
    }

    public function test_an_answer_for_another_call_or_another_contract_is_a_mismatch_not_a_result(): void
    {
        foreach ([
            ['contract' => 'finance-ai.v0'],
            ['correlationId' => 'some-other-call-id-1234'],
            ['purpose' => 'another'],
            ['result' => 'not an object'],
            ['provider' => 'openrouter'],
        ] as $over) {
            $http = new EchoingFakeHttp(['result' => ['a' => 1], 'provider' => ['name' => 'openrouter', 'model' => 'm']] + [], $over);
            $this->expectFailure(
                FinanceAiFailure::CONTRACT_MISMATCH,
                fn () => (new HttpFinanceAiClient($http, 'http://127.0.0.1:8003', self::TOKEN))->complete('p', []),
            );
        }
    }

    public function test_the_health_probe_is_true_only_for_a_healthy_finance_ai_v1_answer(): void
    {
        $this->http->queue = [['status' => 200, 'body' => '{"contract":"finance-ai.v1","status":"ok","purposes":[]}']];
        self::assertTrue($this->client()->isAlive());
        self::assertSame('http://127.0.0.1:8003/v1/health', $this->http->calls[0]['url']);
        self::assertSame('Bearer ' . self::TOKEN, $this->http->calls[0]['headers']['Authorization']);

        foreach ([
            ['status' => 503, 'body' => '{"contract":"finance-ai.v1","error":{"code":"unavailable","message":"x"}}'],
            ['status' => 200, 'body' => '{"contract":"rfq-parser.v1","status":"ok"}'],
            ['status' => 200, 'body' => 'nope'],
        ] as $response) {
            $this->http->queue = [$response];
            self::assertFalse($this->client()->isAlive());
        }
        $this->http->transportError = new RuntimeException('refused');
        self::assertFalse($this->client()->isAlive());
    }

    public function test_the_undeployed_client_refuses_every_call_as_unavailable_and_is_never_alive(): void
    {
        $client = new UndeployedFinanceAiClient();

        $this->expectFailure(FinanceAiFailure::UNAVAILABLE, fn () => $client->complete('p', ['a' => 1]));
        self::assertFalse($client->isAlive());
    }

    private function expectFailure(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Expected ' . $code . '.');
        } catch (FinanceAiFailure $failure) {
            self::assertSame($code, $failure->failureCode);
        }
    }
}
