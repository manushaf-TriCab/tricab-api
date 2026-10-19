<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprWorkflowService;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\Exception\NprValidationFailed;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;

/**
 * POST /api/v1/npr/me/requests/{publicId}/submit: send the caller's own draft
 * (or returned request) to its branch manager. Body `{version}`.
 *
 * 422 `NPR_VALIDATION_FAILED` (answers incomplete), 409 `NPR_NO_MANAGER_ROUTE`
 * (the draft stays saved), 409 `NPR_NOT_EDITABLE`, 409 `NPR_VERSION_CONFLICT`;
 * not the caller's, or missing, is 404. Answers with the request as GET would.
 * Gated on `NPR.SELF.SUBMIT`.
 */
final class SubmitNprRequestAction
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
        $version = $this->version($this->body($request));
        if ($version === null) {
            return $this->versionRequired($this->responder);
        }

        try {
            $view = $this->workflow->submit($user, $args['publicId'] ?? '', $version);
        } catch (NprRequestConflict|NprRequestNotEditable|NprValidationFailed|NprWorkflowRefused $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $view === null ? $this->notFound($this->responder) : $this->responder->success(NprRequestPresenter::detail($view));
    }
}
