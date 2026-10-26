<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Action\Finance\Ap\ApActionSupport;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap2\Service\Ap2UserService;

/** GET /users?permission=INVOICES.WRITE: people holding an AP2 permission. Gated on `FINANCE.AP2.ACCESS`. */
final class ListAp2UsersAction
{
    use ApActionSupport;

    public function __construct(
        private readonly Ap2UserService $service,
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
            return $this->ok($this->responder, $this->service->holding(is_string($this->queryOf($request)['permission'] ?? null) ? $this->queryOf($request)['permission'] : ''), 200);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
