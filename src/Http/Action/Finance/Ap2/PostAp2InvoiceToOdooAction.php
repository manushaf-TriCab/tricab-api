<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Action\Finance\Ap\ApActionSupport;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap2\Service\Ap2OdooPostingService;

/** POST /invoices/{id}/post-to-odoo (a draft vendor bill in Odoo). `FINANCE.AP2.EXPORT`. */
final class PostAp2InvoiceToOdooAction
{
    use ApActionSupport;

    public function __construct(
        private readonly Ap2OdooPostingService $service,
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
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->post($actor, (string) ($args['id'] ?? '')), 200);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
