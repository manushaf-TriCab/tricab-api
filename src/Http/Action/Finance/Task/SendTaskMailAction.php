<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskMailService;

/** POST /api/v1/finance/task/mail/send (route 45): `{kind, from, emails: [{task_id, to, cc, subject, body}], attachments}`; at most 20 emails; answers `{sent, results: [{to, ok, error}]}` per email. A send while mail is switched off is reported as "Mail is switched off", never as sent. Gated on `FINANCE.TASK.MAIL.SEND`. */
final class SendTaskMailAction
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
            return $this->ok($this->responder, $this->service->send($actor, $this->bodyOf($request)));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
