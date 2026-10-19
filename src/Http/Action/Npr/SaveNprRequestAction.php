<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprAttachmentService;
use TriCab\Core\Npr\Application\Service\NprRequestService;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;

/**
 * PUT /api/v1/npr/me/requests/{publicId}: save a draft.
 *
 * Body `{version, answers, variants, changeSummary?}`, `version` being the one
 * last read. A save on a stale version is 409 `NPR_VERSION_CONFLICT` and writes
 * nothing; one past editing is 409 `NPR_NOT_EDITABLE`; somebody else's request,
 * or one that does not exist, is 404. Answers with the request as GET would.
 * Gated on `NPR.SELF.CREATE`.
 */
final class SaveNprRequestAction
{
    use NprActionSupport;

    public function __construct(
        private readonly NprRequestService $requests,
        private readonly JsonResponder $responder,
        private readonly NprAttachmentService $attachments,
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
        $body = $this->body($request);
        $version = $body['version'] ?? null;
        if (!is_string($version) || trim($version) === '') {
            return $this->responder->error('NPR_INPUT_INVALID', 'version is required: the version last read.', 422);
        }
        $summary = $body['changeSummary'] ?? null;

        try {
            $view = $this->requests->saveDraft(
                $user,
                $args['publicId'] ?? '',
                $version,
                $body['answers'] ?? null,
                $body['variants'] ?? null,
                is_string($summary) ? $summary : null,
            );
        } catch (InvalidNprInput|NprRequestConflict|NprRequestNotEditable $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $view === null ? $this->notFound($this->responder) : $this->responder->success(NprRequestPresenter::detail($view, $this->attachments->forRequest($view->request->publicId)));
    }
}
