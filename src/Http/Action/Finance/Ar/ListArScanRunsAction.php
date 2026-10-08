<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArScanService;

/** GET /api/v1/finance/ar/scan-runs (source route 30) */
final class ListArScanRunsAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArScanService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            return $this->ok($this->responder, $this->service->runs($this->queryOf($request)['limit'] ?? null));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
