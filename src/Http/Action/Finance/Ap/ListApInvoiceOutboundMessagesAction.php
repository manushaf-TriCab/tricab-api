<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApMailService;

/**
 * GET /api/v1/finance/ap/invoices/{id}/outbound-messages (source route 56): the mail sent about an invoice, newest first. Gated
 * on `FINANCE.AP.INVOICES.READ`; out of scope is a 404.
 */
final class ListApInvoiceOutboundMessagesAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApMailService $service,
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
            return $this->ok($this->responder, $this->service->invoiceHistory($actor, (string) ($args['id'] ?? '')));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
