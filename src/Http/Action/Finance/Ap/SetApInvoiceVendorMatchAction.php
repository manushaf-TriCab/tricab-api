<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceVendorService;

/** PUT /api/v1/finance/ap/invoices/{id}/vendor-match (source route 27): `{odoo_vendor_id}` picks the vendor, `{status: "new"}` marks it as not in Odoo yet, `{status: "auto"}` hands it back to automatic matching. Answers the updated invoice. Gated on `FINANCE.AP.INVOICES.WRITE`. */
final class SetApInvoiceVendorMatchAction
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
            return $this->ok($this->responder, $this->service->setMatch($actor, (string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
