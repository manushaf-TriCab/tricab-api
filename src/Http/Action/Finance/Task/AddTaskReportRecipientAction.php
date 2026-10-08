<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskReportRecipientService;

/** POST /api/v1/finance/task/settings/report-recipients (route 49): `{email, name}`; 201; 409 when the address is already a recipient; linked to the member who holds that address. Gated on `FINANCE.TASK.SETTINGS.MANAGE`. */
final class AddTaskReportRecipientAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskReportRecipientService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            return $this->ok($this->responder, $this->service->add($this->bodyOf($request)), 201);
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
