<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\DTO\ReviewRequest;
use TriCab\Core\Mail\Application\Service\MailReviewService;
use TriCab\Core\Mail\Domain\Exception\MailFailure;

/**
 * POST /api/v1/mail/threads/{id}/review: `MailReviewRequest` in, the updated `MailThreadDetail` out.
 *
 * Gated on `MAIL.ACCESS` because marking read or unread needs no more; the service demands `MAIL.REVIEW`
 * for every other action (403), and the server-side guard refuses an action the thread's state does not
 * allow with 409 `MAIL_ACTION_REFUSED` and `error.blockers`. A refused action changes nothing.
 */
final class ReviewMailThreadAction
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
        try {
            $detail = $this->review->review($actor, $args['id'] ?? '', ReviewRequest::fromInput($request->getParsedBody()));
        } catch (MailFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($detail->toArray());
    }
}
