<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Quotegen;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\CallbackStream;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Http\Response\ServerSentEvent;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Quotegen\Application\QuotegenChatService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;

/**
 * POST /api/v1/rfqs/{publicId}/assistant/messages/stream — one turn of the
 * RFQ's assistant chat as a `text/event-stream`.
 *
 * Body `{message, revision}`: the text, and the revision the person is looking
 * at. Events, in order: `ack`, then any of `progress`, `tool`, `quote_changed`
 * and `delta`, then exactly one of `done` or `error` (`{code, message}`).
 *
 * Off with `QUOTEGEN_AI_ENABLED` (404), unsigned (401) and an RFQ the person
 * cannot see (404) answer as plain JSON before any stream starts. Everything
 * else, including a bad body, is reported inside the stream.
 */
final class StreamQuotegenAssistantMessageAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ContainerInterface $container,
        private readonly ResolveRfqActorService $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);

        if (!$this->settings->quotegenAiEnabled) {
            return $this->responder->error('NOT_FOUND', 'The requested resource was not found.', 404);
        }
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $service = $this->container->get(QuotegenChatService::class);
        if (!$service instanceof QuotegenChatService) {
            return $this->responder->error('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $actor = $this->actors->forUser($user);
        $publicId = strtolower(trim($args['publicId'] ?? ''));
        if ($service->mayUse($actor) && !$service->canSee($actor, $user->emailAddress, $publicId)) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }
        $body = $request->getParsedBody();
        $message = is_array($body) ? ($body['message'] ?? null) : null;
        $revision = is_array($body) ? ($body['revision'] ?? null) : null;
        $email = $user->emailAddress;
        $name = $user->displayName;

        $stream = new CallbackStream(static function (callable $write) use ($service, $actor, $email, $name, $publicId, $message, $revision): void {
            // A turn of model rounds outlasts PHP's default time limit; the turn is bounded by its own deadline.
            @set_time_limit(0);
            $write(ServerSentEvent::opening());
            $service->turn(
                $actor,
                $email,
                $name,
                $publicId,
                $message,
                $revision,
                static function (string $event, array $data) use ($write): void {
                    $write(ServerSentEvent::frame($event, $data));
                },
            );
        });

        return (new Response(200))
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-transform')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody($stream);
    }
}
