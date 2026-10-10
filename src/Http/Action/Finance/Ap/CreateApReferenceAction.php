<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApReferenceService;

/** POST /api/v1/finance/ap/{companies|cost-centres|account-categories} (source routes 14, 18, 22): a new row, answered whole with a 201. Gated on `FINANCE.AP.SETTINGS.MANAGE`. */
final class CreateApReferenceAction
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
        try {
            return $this->ok($this->responder, $this->service->create((string) ($args['list'] ?? ''), $this->bodyOf($request)), 201);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
