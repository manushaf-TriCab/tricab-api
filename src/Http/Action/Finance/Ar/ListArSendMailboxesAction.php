<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Service\ArMailService;

/** GET /api/v1/finance/ar/send-mailboxes (source route 21): the mailboxes this person may send from, the configured one first. */
final class ListArSendMailboxesAction
{
    use ArActionSupport;

    public function __construct(
        private readonly ArMailService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        $actor = $this->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->sendMailboxes($actor));
        } catch (ArFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
