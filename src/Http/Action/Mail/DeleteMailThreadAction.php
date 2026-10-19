<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\Service\MailReviewService;
use TriCab\Core\Mail\Domain\Exception\MailFailure;

/**
 * DELETE /api/v1/mail/threads/{id}: takes the conversation out of the inbox for everyone; `{ id, deleted: true }` out.
 *
 * Gated on `MAIL.REVIEW`; the service also needs `MAIL.ACCESS` (403 without it). A thread that is not there,
 * or is already deleted, is 404. Nothing is deleted in Exchange.
 */
final class DeleteMailThreadAction
{
    use MailActionSupport;

    public function __construct(private readonly MailReviewService $review, private readonly JsonResponder $responder)
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
        $threadId = $args['id'] ?? '';
        try {
            $this->review->delete($actor, $threadId);
        } catch (MailFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success(['id' => $threadId, 'deleted' => true]);
    }
}
