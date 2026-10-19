<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprRequestService;

/**
 * GET /api/v1/npr/tsd/requests/{publicId}: one request that has reached TSD.
 * A draft, or one still with a manager or Sales, is 404 here, as is a missing
 * one. Gated on `NPR.TSD.READ`.
 */
final class GetTsdNprRequestAction
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
        $view = $this->requests->getForTsd($user, $args['publicId'] ?? '');

        return $view === null ? $this->notFound($this->responder) : $this->responder->success(NprRequestPresenter::detail($view));
    }
}
