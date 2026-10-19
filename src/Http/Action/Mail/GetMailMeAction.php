<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\Service\MailInboxService;

/** GET /api/v1/mail/me: what this person may do in the inbox, and the mailboxes. Gated on `MAIL.ACCESS`. */
final class GetMailMeAction
{
    use MailActionSupport;

    public function __construct(private readonly MailInboxService $inbox, private readonly JsonResponder $responder)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $actor = $this->actor($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }

        return $this->responder->success($this->inbox->me($actor));
    }
}
