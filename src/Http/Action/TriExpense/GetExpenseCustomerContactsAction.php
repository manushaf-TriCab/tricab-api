<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\Admin\CustomerManagementDtoSerializer;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\Admin\ContactListQuery;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\GetExpenseCustomerContactsService;

final class GetExpenseCustomerContactsAction
{
    public function __construct(
        private readonly GetExpenseCustomerContactsService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            $criteria = ContactListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->service->execute(
                ExpenseActorFactory::fromRequest($request),
                (string) ($args['code'] ?? ''),
                $criteria,
            );

            return $this->responder->success(CustomerManagementDtoSerializer::contactListResult($result));
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
