<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Service\ArOptionService;

/** GET /api/v1/finance/ar/settings/options (source route 12): the Category and Method lists with usage counts. Gated on `FINANCE.AR.RECORDS.READ`. */
final class ListArOptionsAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArOptionService $service,
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

        return $this->ok($this->responder, $this->service->lists());
    }
}
