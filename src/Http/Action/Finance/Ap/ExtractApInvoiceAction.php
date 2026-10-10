<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApExtractService;

/** POST /api/v1/finance/ap/extract (source route 48): `{upload_id}` of the person's own unattached upload; the model reads it and the answer is the capture dialog's fields. No prompt is accepted (decision Q8). Gated on `FINANCE.AP.INVOICES.WRITE`. */
final class ExtractApInvoiceAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApExtractService $service,
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
            return $this->ok($this->responder, $this->service->extract($actor, $this->bodyOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
