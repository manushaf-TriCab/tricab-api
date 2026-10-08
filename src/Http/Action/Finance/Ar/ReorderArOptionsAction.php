<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArOptionService;

/** PUT /api/v1/finance/ar/settings/options/{list}/order (source route 16): `{values: [...]}`, every option once. Gated on `FINANCE.AR.ADMIN`. */
final class ReorderArOptionsAction
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
        try {
            return $this->ok($this->responder, $this->service->reorder((string) ($args['list'] ?? ''), $this->bodyOf($request)['values'] ?? []));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
