<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApReferenceService;

/** PATCH /api/v1/finance/ap/{companies|cost-centres|account-categories}/{id} (source routes 15, 19, 23): changes the keys the body carries; an unknown id is a 404. Gated on `FINANCE.AP.SETTINGS.MANAGE`. */
final class UpdateApReferenceAction
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
            return $this->ok($this->responder, $this->service->update((string) ($args['list'] ?? ''), (string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
