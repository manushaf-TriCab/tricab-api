<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprRequestService;

/**
 * GET /api/v1/npr/me/requests/{publicId}: one of the caller's own requests.
 * Somebody else's, or one that does not exist, is 404. Gated on `NPR.SELF.READ`.
 */
final class GetMyNprRequestAction
{
    use NprActionSupport;

    public function __construct(
        private readonly NprRequestService $requests,
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
        $view = $this->requests->getOwn($user, $args['publicId'] ?? '');

        return $view === null ? $this->notFound($this->responder) : $this->responder->success(NprRequestPresenter::detail($view));
    }
}
