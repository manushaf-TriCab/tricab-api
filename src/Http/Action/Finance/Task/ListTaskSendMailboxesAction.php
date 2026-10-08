<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskMailService;

/** GET /api/v1/finance/task/send-mailboxes (source route 44): the active mailboxes Tri-Task holds a send grant on, narrowed by the person's mailbox scope (no scope rows means all), and the default (`FINANCE_TASK_MAIL_FROM`). Gated on `FINANCE.TASK.MAIL.SEND`. */
final class ListTaskSendMailboxesAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskMailService $service,
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
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
