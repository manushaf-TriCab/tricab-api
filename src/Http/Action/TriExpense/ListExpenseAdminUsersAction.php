<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\ListExpenseAdminUsersService;

final class ListExpenseAdminUsersAction
{
    public function __construct(
        private readonly ListExpenseAdminUsersService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $params = $request->getQueryParams();
            $search = is_string($params['search'] ?? null) ? (string) $params['search'] : '';

            return $this->responder->success(
                $this->service->execute(ExpenseActorFactory::fromRequest($request), $search),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
