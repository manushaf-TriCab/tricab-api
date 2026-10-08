<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Service\ArMeService;

/** GET /api/v1/finance/ar/me (source route 5): the person, the portal permission strings, the mailbox and the Tri-Credit Notes link (decisions D50, Q12). Gated on `FINANCE.AR.ACCESS`: a person with the app but not `RECORDS.READ` gets an honest, empty permission list rather than a 403. */
final class GetArMeAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArMeService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        unset($args);

        return $this->ok($this->responder, $this->service->me($actor));
    }
}
