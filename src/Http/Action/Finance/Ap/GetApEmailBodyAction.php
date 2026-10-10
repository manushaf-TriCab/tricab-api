<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApMessageService;

/** GET /api/v1/finance/ap/emails/{id}/body (source route 35): the message body, read live from the mailbox. The content is untrusted and returned unmodified (the page shows it in a sandboxed frame); it is not cached. Gated on `FINANCE.AP.MAIL.READ`. */
final class GetApEmailBodyAction
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
            return $this->ok($this->responder, $this->service->body($actor, (string) ($args['id'] ?? '')))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
