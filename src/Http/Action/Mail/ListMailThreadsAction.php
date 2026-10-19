<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Application\Service\MailInboxService;
use TriCab\Core\Mail\Domain\Exception\MailFailure;

/**
 * GET /api/v1/mail/threads?queue&search&sort&unreadOnly&attachments&cursor&pageSize: one page of the list,
 * newest first by default. Gated on `MAIL.ACCESS`.
 */
final class ListMailThreadsAction
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
        try {
            $page = $this->inbox->threads(ThreadQuery::fromInput(array_filter($request->getQueryParams(), 'is_string', ARRAY_FILTER_USE_KEY)));
        } catch (MailFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($page->toArray());
    }
}
