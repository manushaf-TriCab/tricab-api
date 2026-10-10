<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApEmailService;

/** GET /api/v1/finance/ap/emails (source route 33): the scanned emails with their invoice ids, or the Bin with `?deleted=1`. Query: `fromDate`, `toDate`, `classification`, `deleted`, `limit` (at most 1,000), `offset`. Mailbox scope applied in the query. Gated on `FINANCE.AP.MAIL.READ`. */
final class ListApEmailsAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApEmailService $service,
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
            return $this->ok($this->responder, $this->service->list($actor, $this->queryOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
