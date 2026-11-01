<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Service\Ap2PaymentService;

/** GET /payments. `FINANCE.AP2.INVOICES.READ`. */
final class ListAp2PaymentsAction
{
    use Ap2ActionSupport;

    public function __construct(
        private readonly Ap2PaymentService $service,
        private readonly Ap2ActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        if ($this->actors->actorOf($request) === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->list(), 200);
        } catch (Ap2Failure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
