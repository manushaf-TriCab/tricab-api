<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApApprovalService;

/** GET /api/v1/finance/ap/approvers (source route 56): the people who may complete an approval request, `{id, full_name, email}`. Gated on `FINANCE.AP.INVOICES.READ`. */
final class ListApApproversAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApApprovalService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($request, $response, $args);
        try {
            return $this->ok($this->responder, $this->service->approvers());
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
