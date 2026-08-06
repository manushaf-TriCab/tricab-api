<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\ContactListQuery;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Service\ListCustomerContactsService;

final class ListCustomerContactsAction
{
    public function __construct(
        private readonly ListCustomerContactsService $listCustomerContacts,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $customerPublicId = RouteParams::require($request, 'customerPublicId');
            $criteria = ContactListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->listCustomerContacts->execute($customerPublicId, $criteria);

            return $this->responder->success(CustomerManagementDtoSerializer::contactListResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
