<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\NprRequestService;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;

/**
 * POST /api/v1/npr/me/requests: open a draft. Body `{answers?, variants?}`.
 * The requester is the signed-in person; the body cannot name one. Answers 201
 * with the request as GET would. Gated on `NPR.SELF.CREATE`.
 */
final class CreateNprRequestAction
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
        $body = $this->body($request);

        try {
            $view = $this->requests->createDraft($user, $body['answers'] ?? null, $body['variants'] ?? null);
        } catch (InvalidNprInput $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success(NprRequestPresenter::detail($view), 201);
    }
}
