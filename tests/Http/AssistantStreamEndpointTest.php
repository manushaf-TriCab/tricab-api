<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\AssistantTurnEvents;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\AssistantMessageStreamAction;
use TriCab\Api\Http\Auth\AssistantCatalogueVisibilityResolver;
use TriCab\Api\Http\Auth\AssistantErrorMapper;
use TriCab\Api\Http\Response\CallbackStream;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Tests\TestAppFactory;

final class AssistantStreamEndpointTest extends TestCase
{
    public function test_streams_the_same_reply_the_plain_endpoint_returns(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $body = [
            'message' => 'Which cable is suitable for this application?',
            'searchQuery' => 'nonexistent cable',
        ];

        $response = $app->handle($this->jsonRequest('/api/v1/assistant/messages/stream', $body));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
        self::assertSame('no-cache, no-transform', $response->getHeaderLine('Cache-Control'));

        $events = $this->events($response);
        self::assertSame(['done'], array_column($events, 'event'), 'the deterministic provider streams no text, only the reply');
        $done = $events[0]['data'];
        self::assertIsArray($done['provider']);
        self::assertIsArray($done['message']);
        self::assertSame('deterministic-search', $done['provider']['mode']);
        self::assertSame('assistant', $done['message']['role']);
        self::assertSame(['visibility' => 'public', 'includesUnpublished' => false], $done['catalogue']);

        $plain = TestAppFactory::json($app->handle($this->jsonRequest('/api/v1/assistant/messages', $body)));
        self::assertIsArray($plain['data']);
        self::assertSame(array_keys($plain['data']), array_keys($done), 'done carries the plain endpoint\'s data object');
    }

    public function test_an_invalid_request_gets_the_plain_json_error_and_no_stream(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle($this->jsonRequest('/api/v1/assistant/messages/stream', ['message' => '']));

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $payload = TestAppFactory::json($response);
        self::assertFalse($payload['success']);
    }

    public function test_an_unexpected_throwable_ends_the_stream_with_a_generic_error_event(): void
    {
        $container = ContainerFactory::create(Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'APP_PERFORMANCE_LOGGING' => 'false',
            'USER_REPOSITORY' => 'memory',
            'APP_CACHE_DRIVER' => 'array',
        ]));
        $container->set(AssistantResponseProvider::class, new class implements AssistantResponseProvider {
            public function generate(AssistantGrounding $grounding): AssistantProviderResult
            {
                throw new \Error('SECRET internal detail');
            }
        });
        $visibility = $container->get(AssistantCatalogueVisibilityResolver::class);
        self::assertInstanceOf(AssistantCatalogueVisibilityResolver::class, $visibility);
        $action = new AssistantMessageStreamAction(
            $container,
            new AssistantErrorMapper(new JsonResponder()),
            $visibility,
            new AssistantTurnEvents(),
        );

        $request = $this->jsonRequest('/api/v1/assistant/messages/stream', ['message' => 'Find KL cable', 'searchQuery' => 'KL']);
        $response = $action($request, new Response());

        $events = $this->events($response);
        $last = $events[count($events) - 1];
        self::assertSame('error', $last['event']);
        self::assertNotContains('done', array_column($events, 'event'));
        $error = $last['data'];
        self::assertSame('ASSISTANT_UNAVAILABLE', $error['code']);
        self::assertSame('The product assistant is temporarily unavailable.', $error['message']);
        self::assertStringNotContainsString('SECRET', json_encode($events, JSON_THROW_ON_ERROR));
    }

    /** @return list<array{event: string, data: array<string, mixed>}> */
    private function events(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        self::assertInstanceOf(CallbackStream::class, $stream);
        $raw = '';
        $stream->drain(static function (string $chunk) use (&$raw): void {
            $raw .= $chunk;
        });
        self::assertTrue($stream->eof());

        $events = [];
        foreach (explode("\n\n", trim($raw)) as $frame) {
            if (str_starts_with($frame, ':')) {
                continue;
            }
            if (preg_match('/^event: (\S+)\ndata: (.+)$/', $frame, $match) !== 1) {
                self::fail('Not an event frame: ' . $frame);
            }
            $data = json_decode($match[2], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            /** @var array<string, mixed> $data */
            $events[] = ['event' => $match[1], 'data' => $data];
        }

        return $events;
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request('POST', $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
