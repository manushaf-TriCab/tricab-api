<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\Service\MailInboxService;
use TriCab\Core\Mail\Domain\Exception\MailFailure;

/** GET /api/v1/mail/threads/{id}: the messages, assessment, decision, activity, links and what this person may do. Gated on `MAIL.ACCESS`. */
final class GetMailThreadAction
{
    use MailActionSupport;

    public function __construct(private readonly MailInboxService $inbox, private readonly JsonResponder $responder)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actor($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $detail = $this->inbox->thread($actor, $args['id'] ?? '');
        } catch (MailFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($detail->toArray());
    }
}
