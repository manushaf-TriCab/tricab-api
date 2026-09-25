<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\SearchExpenseAdminEmployeesService;

final class SearchExpenseAdminEmployeesAction
{
    public function __construct(
        private readonly SearchExpenseAdminEmployeesService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $params = $request->getQueryParams();
            $query = is_string($params['q'] ?? null) ? (string) $params['q'] : '';
            $purpose = is_string($params['purpose'] ?? null)
                ? strtolower(trim((string) $params['purpose']))
                : SearchExpenseAdminEmployeesService::PURPOSE_TE_USER;

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $query,
                    $purpose,
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
