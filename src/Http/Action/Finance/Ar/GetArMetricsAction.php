<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArRecordService;

/** GET /api/v1/finance/ar/dashboard/metrics (source route 31): the tiles for the chosen Melbourne day range; the Bin count and the credit-note counts are never bounded by it. Gated on `FINANCE.AR.RECORDS.READ`. */
final class GetArMetricsAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArRecordService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        unset($args);
        $query = $this->queryOf($request);
        try {
            return $this->ok($this->responder, $this->service->metrics($query['from_date'] ?? null, $query['to_date'] ?? null));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
