<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApMessageService;

/** GET /api/v1/finance/ap/emails/{id}/attachments (source route 36): the files of the message, listed live from the mailbox; rows `{id, name, content_type, size}`. Gated on `FINANCE.AP.MAIL.READ`. */
final class ListApEmailAttachmentsAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApMessageService $service,
        private readonly ApActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->attachments($actor, (string) ($args['id'] ?? '')))
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
