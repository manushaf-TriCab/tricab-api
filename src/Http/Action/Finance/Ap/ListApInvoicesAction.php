<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;

/** GET /api/v1/finance/ap/invoices (source route 7): the register, or the Bin with `?deleted=1`. Query: `sort`, `limit`, `id`, `status`, `deleted`, `fromDate`, `toDate`. Applies the person's mailbox scope before the limit. Gated on `FINANCE.AP.INVOICES.READ`. */
final class ListApInvoicesAction
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
            return $this->ok($this->responder, $this->service->list($actor, $this->queryOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
