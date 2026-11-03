<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Quotegen;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Quotegen\Application\QuotegenChatService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;

/**
 * GET /api/v1/rfqs/{publicId}/assistant — the RFQ's shared assistant
 * conversation and its budget. Created on first read.
 *
 * Switched off with `QUOTEGEN_AI_ENABLED`: while it is not exactly `true`, this
 * answers the platform's ordinary 404. The signed-in person is resolved on the
 * server; nothing in the request selects identity, permissions or scope.
 */
final class GetQuotegenAssistantAction
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
        if (!$service->mayUse($actor)) {
            return $this->responder->error('RFQ_ACTION_FORBIDDEN', 'You do not have access to this quote.', 403);
        }
        if (!$service->canSee($actor, $user->emailAddress, $publicId)) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        return $this->responder->success($service->history($actor, $publicId));
    }
}
