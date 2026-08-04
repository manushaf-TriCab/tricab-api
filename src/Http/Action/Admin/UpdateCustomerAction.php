<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\Admin\UpdateCustomerRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Service\UpdateCustomerService;

final class UpdateCustomerAction
{
    public function __construct(
        private readonly UpdateCustomerService $updateCustomer,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'customerPublicId');
            $body = JsonRequestBody::requireObject($request);
            $input = UpdateCustomerRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var CustomerDetails $updated */
            $updated = $this->transaction->run(fn (): CustomerDetails => $this->updateCustomer->execute(
                actor: $actor,
                publicId: $publicId,
                customerName: $input->customerName,
                customerCode: $input->customerCode,
                addressLine1: $input->addressLine1,
                addressLine2: $input->addressLine2,
                suburb: $input->suburb,
                postcode: $input->postcode,
                countryCode: $input->countryCode,
                marketCode: $input->marketCode,
                salesRep: $input->salesRep,
                territory: $input->territory,
                phone: $input->phone,
            ));

            return $this->responder->success(CustomerManagementDtoSerializer::customerDetails($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
