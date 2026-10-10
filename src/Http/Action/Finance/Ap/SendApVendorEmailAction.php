<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApMailService;

/**
 * POST /api/v1/finance/ap/invoices/{id}/send-email (source route 55): a vendor email, optionally with the invoice document.
 * Answers the outbound row. Gated on `FINANCE.AP.MAIL.SEND`; refused with a 503 while `FINANCE_AP_SENDING_ENABLED` is off.
 */
final class SendApVendorEmailAction
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
            return $this->ok($this->responder, $this->service->sendVendorEmail($actor, (string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
