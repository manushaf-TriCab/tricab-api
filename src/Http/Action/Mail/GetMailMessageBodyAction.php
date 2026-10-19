<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\Service\MailMessageService;
use TriCab\Core\Mail\Domain\Exception\MailFailure;

/**
 * GET /api/v1/mail/messages/{id}/body: the body, fetched from Exchange now and cleaned on the server
 * (remote images stripped). 503 `MAIL_BODY_UNAVAILABLE` while the RFQ mail Graph settings are empty.
 * Never cached: it is customer correspondence. Gated on `MAIL.ACCESS`.
 */
final class GetMailMessageBodyAction
{
    use MailActionSupport;

    public function __construct(private readonly MailMessageService $messages, private readonly JsonResponder $responder)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        if ($this->actor($request) === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $body = $this->messages->body($args['id'] ?? '');
        } catch (MailFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($body->toArray(), 200, ['Cache-Control' => 'private, no-store']);
    }
}
