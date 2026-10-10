<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceVendorService;

/** POST /api/v1/finance/ap/vendor-matching/run (source route 28): matches the live invoices the person may see that nobody has decided on; `{invoice_ids}` narrows it. Answers `{checked, matched, unmatched}`. Gated on `FINANCE.AP.INVOICES.WRITE`. */
final class RunApVendorMatchingAction
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
        unset($response, $args);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->run($actor, $this->bodyOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
