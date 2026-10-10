<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApMailService;

/**
 * POST /api/v1/finance/ap/emails/{id}/send-reply (source route 52): a reply to a scanned email, threaded when it goes to the
 * original sender from its mailbox. Answers the outbound row and `threaded`. Gated on `FINANCE.AP.MAIL.SEND`; refused with a
 * 503 while sending is off.
 */
final class SendApEmailReplyAction
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
            return $this->ok($this->responder, $this->service->sendReply($actor, (string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
