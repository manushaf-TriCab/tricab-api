<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApMailService;

/**
 * GET /api/v1/finance/ap/send-mailboxes (source route 50): the mailboxes this person may send from, the configured one first.
 * Gated on `FINANCE.AP.INVOICES.READ`.
 */
final class ListApSendMailboxesAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApMailService $service,
        private readonly ApActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actors->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->sendMailboxes($actor));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
