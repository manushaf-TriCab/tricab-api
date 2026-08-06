<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\CustomerListQuery;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Service\ListCustomersService;

final class ListCustomersAction
{
    public function __construct(
        private readonly ListCustomersService $listCustomers,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $criteria = CustomerListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->listCustomers->execute($criteria);

            return $this->responder->success(CustomerManagementDtoSerializer::customerListResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
