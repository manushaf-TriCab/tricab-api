<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\PrepareManagerReminderService;

final class PrepareManagerReminderAction
{
    public function __construct(
        private readonly PrepareManagerReminderService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            return $this->responder->success([
                'prepared' => $this->service->execute(ExpenseActorFactory::fromRequest($request)),
            ]);
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
