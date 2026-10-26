<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Action\Finance\Ap\ApActionSupport;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap2\Service\Ap2PriorityService;

/** PUT /priorities/{kind}/{id}. Gated on `FINANCE.AP2.ACCESS`. */
final class PutAp2PriorityAction
{
    use ApActionSupport;

    public function __construct(
        private readonly Ap2PriorityService $service,
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
            return $this->ok($this->responder, $this->service->put($actor, (string) ($args['kind'] ?? ''), (string) ($args['id'] ?? ''), $this->bodyOf($request)), 200);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
