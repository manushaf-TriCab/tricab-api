<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprRequestService;

/**
 * GET /api/v1/npr/me/requests: the signed-in person's own requests, newest first
 * (headers only). Gated on `NPR.SELF.READ`. There is no owner parameter.
 */
final class ListMyNprRequestsAction
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
            'items' => array_map(NprRequestPresenter::summary(...), $this->requests->listOwn($user)),
        ]);
    }
}
