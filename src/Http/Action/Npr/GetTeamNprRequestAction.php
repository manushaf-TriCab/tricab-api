<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprWorkflowService;

/**
 * GET /api/v1/npr/team/requests/{publicId}: one request assigned to the caller
 * as manager. Anyone else's, a draft, or a missing one is 404. Gated on
 * `NPR.TEAM.ENDORSE`.
 */
final class GetTeamNprRequestAction
{
    use NprActionSupport;

    public function __construct(
        private readonly NprWorkflowService $workflow,
        private readonly JsonResponder $responder,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $user = $this->user($request);
        if ($user === null) {
            return $this->unauthenticated($this->responder);
        }
        $view = $this->workflow->getForManager($user, $args['publicId'] ?? '');

        return $view === null ? $this->notFound($this->responder) : $this->responder->success(NprRequestPresenter::detail($view));
    }
}
