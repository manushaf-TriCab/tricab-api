<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprDraftDeletionService;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotDeletable;

/**
 * DELETE /api/v1/npr/me/requests/{publicId}: the requester removes their own
 * never-submitted draft. Answers `{ data: { publicId, deleted: true } }`.
 *
 * 409 `NPR_NOT_DELETABLE` (submitted at any time, or not a draft); not the
 * caller's, or missing, is 404. Gated on `NPR.SELF.CREATE`, as editing a draft.
 */
final class DeleteNprRequestAction
{
    use NprActionSupport;

    public function __construct(
        private readonly NprDraftDeletionService $deletion,
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
        $publicId = $args['publicId'] ?? '';

        try {
            $deleted = $this->deletion->delete($user, $publicId);
        } catch (NprRequestNotDeletable $failure) {
            return $this->responder->error('NPR_NOT_DELETABLE', $failure->getMessage(), 409);
        }

        return $deleted ? $this->responder->success(['publicId' => strtolower($publicId), 'deleted' => true]) : $this->notFound($this->responder);
    }
}
