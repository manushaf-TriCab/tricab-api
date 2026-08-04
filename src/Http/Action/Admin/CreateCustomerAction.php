<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\CreateCustomerRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Service\CreateCustomerService;

final class CreateCustomerAction
{
    public function __construct(
        private readonly CreateCustomerService $createCustomer,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = CreateCustomerRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var CustomerDetails $created */
            $created = $this->transaction->run(fn (): CustomerDetails => $this->createCustomer->execute(
                actor: $actor,
                customerName: $input->customerName,
                customerCode: $input->customerCode,
                status: $input->status,
                addressLine1: $input->addressLine1,
                addressLine2: $input->addressLine2,
                suburb: $input->suburb,
                postcode: $input->postcode,
                countryCode: $input->countryCode,
                marketCode: $input->marketCode,
                salesRep: $input->salesRep,
                territory: $input->territory,
                phone: $input->phone,
                legacyCustomerId: $input->legacyCustomerId,
            ));

            return $this->responder->success(CustomerManagementDtoSerializer::customerDetails($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
