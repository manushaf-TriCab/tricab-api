<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\Service\MailInboxService;

/** GET /api/v1/mail/queues: the sidebar counts, total and unread, for every queue. Gated on `MAIL.ACCESS`. */
final class ListMailQueuesAction
{
    use MailActionSupport;

    public function __construct(private readonly MailInboxService $inbox, private readonly JsonResponder $responder)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        if ($this->actor($request) === null) {
            return $this->unauthenticated($this->responder);
        }

        return $this->responder->success($this->inbox->queues());
    }
}
