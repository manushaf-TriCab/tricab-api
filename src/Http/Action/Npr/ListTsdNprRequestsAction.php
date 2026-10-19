<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprRequestService;

/**
 * GET /api/v1/npr/tsd/requests: the TSD queue, requests with TSD or later
 * (headers only), newest first. Gated on `NPR.TSD.READ`.
 */
final class ListTsdNprRequestsAction
{
    use NprActionSupport;

    public function __construct(
        private readonly NprRequestService $requests,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $user = $this->user($request);
        if ($user === null) {
            return $this->unauthenticated($this->responder);
        }

        return $this->responder->success([
            'items' => array_map(NprRequestPresenter::summary(...), $this->requests->listForTsd($user)),
        ]);
    }
}
