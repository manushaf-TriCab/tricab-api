<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;

/** POST /api/v1/finance/ap/invoices (source route 9): a manual capture; `upload_id` names the file `POST /uploads` kept. Answers 201 with the new row. Gated on `FINANCE.AP.INVOICES.WRITE`. */
final class CreateApInvoiceAction
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
            return $this->ok($this->responder, $this->service->create($actor, $this->bodyOf($request)), 201);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
