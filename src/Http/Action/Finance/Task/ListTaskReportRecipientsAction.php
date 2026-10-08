<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskReportRecipientService;

/** GET /api/v1/finance/task/settings/report-recipients (source route 48): who gets the daily summary, by email. Gated on `FINANCE.TASK.SETTINGS.MANAGE`. */
final class ListTaskReportRecipientsAction
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
        unset($response, $request, $args);
        try {
            return $this->ok($this->responder, $this->service->list());
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
