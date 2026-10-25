<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskCalendarInviteService;

/** POST /api/v1/finance/task/calendar-invites: `{ids: [...], link_origin?}`, at most 20 tasks; one all-day invite per task with a due date, to every preparer and reviewer, from the caller's own mailbox. Answers `{sent, skipped, failed, results: [...]}`; a task with no due date is skipped with the reason. Gated on `FINANCE.TASK.MAIL.SEND`, as reminders are. */
final class SendTaskCalendarInvitesAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskCalendarInviteService $service,
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
        $body = $this->bodyOf($request);
        $origin = $body['link_origin'] ?? '';
        try {
            return $this->ok($this->responder, $this->service->send($actor, $body['ids'] ?? null, is_string($origin) ? $origin : ''));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
