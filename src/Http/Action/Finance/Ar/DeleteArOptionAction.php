<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArOptionService;

/** DELETE /api/v1/finance/ar/settings/options/{list}/{value} (source route 15): deletes an unused option, archives a used one; a built-in is refused. Gated on `FINANCE.AR.ADMIN`. */
final class DeleteArOptionAction
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
        unset($request);
        try {
            return $this->ok($this->responder, $this->service->delete((string) ($args['list'] ?? ''), (string) ($args['value'] ?? '')));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
