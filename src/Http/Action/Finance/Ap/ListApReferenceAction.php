<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApReferenceService;

/**
 * GET /api/v1/finance/ap/{companies|cost-centres|account-categories} (source routes 13, 17, 21):
 * `?sort=` (a column, `-` for descending) and `?limit=` (default 200, at most 1,000). The list comes
 * from the route's `list` argument. Gated on `FINANCE.AP.INVOICES.READ`.
 */
final class ListApReferenceAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApReferenceService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $query = $this->queryOf($request);
        try {
            return $this->ok($this->responder, $this->service->list((string) ($args['list'] ?? ''), $query['sort'] ?? null, $query['limit'] ?? null));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
