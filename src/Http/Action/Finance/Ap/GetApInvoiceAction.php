<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;

/** GET /api/v1/finance/ap/invoices/{id} (source route 8): one invoice, live or binned; 404 `Not found` for a missing one or one outside the person's mailbox scope. Gated on `FINANCE.AP.INVOICES.READ`. */
final class GetApInvoiceAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApInvoiceService $service,
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
            return $this->ok($this->responder, $this->service->get($actor, (string) ($args['id'] ?? '')));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
