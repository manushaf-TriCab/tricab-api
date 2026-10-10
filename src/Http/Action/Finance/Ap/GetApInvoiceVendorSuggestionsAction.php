<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceVendorService;

/** GET /api/v1/finance/ap/invoices/{id}/vendor-suggestions (source route 26): `{suggestions, buyer_like}` for an invoice the person may see (out of scope is a 404). Gated on `FINANCE.AP.INVOICES.READ`. */
final class GetApInvoiceVendorSuggestionsAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApInvoiceVendorService $service,
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
            return $this->ok($this->responder, $this->service->suggestions($actor, (string) ($args['id'] ?? '')));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
