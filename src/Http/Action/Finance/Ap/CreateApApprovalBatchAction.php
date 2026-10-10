<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApApprovalService;

/** POST /api/v1/finance/ap/approval-batches (source route 60): asks the Approvers to sign off invoices; answers `{batch, warning}` with 201. Gated on `FINANCE.AP.APPROVALS.PREPARE`. */
final class CreateApApprovalBatchAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApApprovalService $service,
        private readonly ApActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->request($actor, $this->bodyOf($request)), 201);
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
