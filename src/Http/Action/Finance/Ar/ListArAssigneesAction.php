<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Service\ArMeService;

/** GET /api/v1/finance/ar/assignees (source route 11): active users holding `FINANCE.AR.RECORDS.WRITE` (decision D43). Gated on `FINANCE.AR.RECORDS.READ`. */
final class ListArAssigneesAction
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
        unset($request, $args);

        return $this->ok($this->responder, $this->service->assignees());
    }
}
