<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprWorkflowService;

/**
 * GET /api/v1/npr/tsd/screening-signatories: who TSD can nominate on
 * request-screening, so the page offers a list instead of a typed address.
 * `configured` is false while `NPR_SCREENING_SIGNATORY_EMAILS` is empty (the
 * step answers NPR_SIGNING_POLICY_NOT_CONFIGURED then). Gated on `NPR.TSD.WORK`,
 * the same permission as the step.
 */
final class ListNprScreeningSignatoriesAction
{
    use NprActionSupport;

    public function __construct(
        private readonly NprWorkflowService $workflow,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        if ($this->user($request) === null) {
            return $this->unauthenticated($this->responder);
        }
        $people = $this->workflow->screeningSignatories();

        return $this->responder->success([
            'configured' => $this->workflow->signingConfigured(),
            'items' => array_map(NprRequestPresenter::person(...), $people),
        ]);
    }
}
