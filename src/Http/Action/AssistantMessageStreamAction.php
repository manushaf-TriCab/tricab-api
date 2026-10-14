<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Psr7\Response;
use Throwable;
use TriCab\Api\Assistant\Application\AssistantTurnEvents;
use TriCab\Api\Assistant\Application\ProductAssistantService;
use TriCab\Api\Http\Auth\AssistantCatalogueVisibilityResolver;
use TriCab\Api\Http\Auth\AssistantErrorMapper;
use TriCab\Api\Http\Request\AssistantMessageRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\CallbackStream;
use TriCab\Api\Http\Response\ServerSentEvent;

/**
 * The product assistant as a `text/event-stream`: the same request and the same
 * reply as {@see AssistantMessageAction}, with the reply's text sent as the
 * model writes it.
 *
 * Events, in order: any number of `text` `{text}` and `tool` `{name}`, then
 * exactly one of `done` (the `data` object the plain endpoint returns) or
 * `error` (`{code, message}`, as its error body would carry). A request that
 * fails validation never starts a stream; it gets the plain endpoint's JSON
 * error and status.
 */
final class AssistantMessageStreamAction
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly AssistantErrorMapper $errors,
        private readonly AssistantCatalogueVisibilityResolver $catalogueVisibility,
        private readonly AssistantTurnEvents $events,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $assistant = $this->container->get(ProductAssistantService::class);
            if (!$assistant instanceof ProductAssistantService) {
                throw new RuntimeException('Product assistant service is unavailable.');
            }
            $input = AssistantMessageRequest::fromArray(JsonRequestBody::requireObject($request));
            $visibility = $this->catalogueVisibility->resolve($request);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }

        $events = $this->events;
        $errors = $this->errors;
        $body = new CallbackStream(static function (callable $write) use ($assistant, $input, $visibility, $events, $errors): void {
            $write(ServerSentEvent::opening());
            $events->listen(static function (string $event, array $data) use ($write): void {
                $write(ServerSentEvent::frame($event, $data));
            });
            try {
                $reply = $assistant->reply(
                    message: $input->message,
                    searchQuery: $input->searchQuery,
                    regionCode: $input->regionCode,
                    conversationId: $input->conversationId,
                    catalogueVisibility: $visibility,
                );
                $write(ServerSentEvent::frame('done', $reply));
            } catch (Throwable $exception) {
                $write(ServerSentEvent::frame('error', self::errorBody($errors, $exception)));
            } finally {
                $events->listen(null);
            }
        });

        return (new Response(200))
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-transform')
            // nginx buffers FastCGI responses unless told otherwise.
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody($body);
    }

    /**
     * The status line has already gone, so a failure is reported in the stream,
     * with the code and message the plain endpoint would have sent for it.
     *
     * @return array{code: string, message: string}
     */
    private static function errorBody(AssistantErrorMapper $errors, Throwable $exception): array
    {
        $fallback = ['code' => 'ASSISTANT_UNAVAILABLE', 'message' => 'The product assistant is temporarily unavailable.'];
        try {
            $decoded = json_decode((string) $errors->map($exception)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $fallback;
        }
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        if (!is_array($error) || !is_string($error['code'] ?? null) || !is_string($error['message'] ?? null)) {
            return $fallback;
        }

        return ['code' => $error['code'], 'message' => $error['message']];
    }
}
