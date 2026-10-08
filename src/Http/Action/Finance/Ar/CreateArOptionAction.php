<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArOptionService;

/** POST /api/v1/finance/ar/settings/options/{list} (source route 13): a new option, above "other". Gated on `FINANCE.AR.ADMIN`. */
final class CreateArOptionAction
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
            return $this->ok($this->responder, $this->service->create((string) ($args['list'] ?? ''), $this->bodyOf($request)), 201);
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
