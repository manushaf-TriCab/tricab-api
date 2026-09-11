<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\CustomerManagementDtoSerializer;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\SearchExpenseCustomersService;

final class SearchExpenseCustomersAction
{
    public function __construct(
        private readonly SearchExpenseCustomersService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $query = QueryParams::normalize($request->getQueryParams());
            $q = QueryParams::stringOrNull($query, 'q') ?? QueryParams::stringOrNull($query, 'search');
            $result = $this->service->execute(
                ExpenseActorFactory::fromRequest($request),
                $q,
                QueryParams::int($query, 'page', 1),
                QueryParams::int($query, 'pageSize', 25),
            );

            return $this->responder->success(CustomerManagementDtoSerializer::customerListResult($result));
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
