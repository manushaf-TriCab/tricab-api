<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Service\ApMeService;

/** GET /api/v1/finance/ap/me (source routes 3 and 4): the person, the portal permission strings, the role, the mailbox scope and whether sending is on (decision D78). Gated on `FINANCE.AP.ACCESS`. */
final class GetApMeAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApMeService $service,
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

        return $this->ok($this->responder, $this->service->me($actor));
    }
}
