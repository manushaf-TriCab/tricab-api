<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApApprovalService;

/** POST /api/v1/finance/ap/approval-batches/{id}/decision (source route 61): the first decision wins; answers the decided batch with `notification_warning`, or a 409 carrying `batch`. Gated on `FINANCE.AP.APPROVALS.DECIDE`. */
final class DecideApApprovalBatchAction
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
            return $this->ok($this->responder, $this->service->decide($actor, (string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
