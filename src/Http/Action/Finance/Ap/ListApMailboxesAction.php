<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Service\ApMailboxService;

/** GET /api/v1/finance/ap/mailboxes (source route 29): the registry's mailboxes with a Tri-AP grant, limited to the person's mailbox scope. Read only (question Q14). Gated on `FINANCE.AP.INVOICES.READ`. */
final class ListApMailboxesAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApMailboxService $service,
        private readonly ApActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }

        return $this->ok($this->responder, $this->service->list($actor));
    }
}
