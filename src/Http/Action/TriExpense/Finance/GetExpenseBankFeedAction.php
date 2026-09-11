<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\GetExpenseBankFeedService;

final class GetExpenseBankFeedAction
{
    public function __construct(
        private readonly GetExpenseBankFeedService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            return $this->responder->success(
                $this->service->execute(ExpenseActorFactory::fromRequest($request), (string) ($args['id'] ?? '')),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
