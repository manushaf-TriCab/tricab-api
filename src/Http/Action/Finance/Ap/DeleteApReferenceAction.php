<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApReferenceService;

/** DELETE /api/v1/finance/ap/{companies|cost-centres|account-categories}/{id} (source routes 16, 20, 24): removes the row; an id that is not there still answers `{ok: true}`, as the source did. Gated on `FINANCE.AP.SETTINGS.MANAGE`. */
final class DeleteApReferenceAction
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
        unset($request, $response);
        try {
            return $this->ok($this->responder, $this->service->delete((string) ($args['list'] ?? ''), (string) ($args['id'] ?? '')));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
