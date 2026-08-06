<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\CreateCustomerContactRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Service\CreateCustomerContactService;

final class CreateCustomerContactAction
{
    public function __construct(
        private readonly CreateCustomerContactService $createCustomerContact,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $customerPublicId = RouteParams::require($request, 'customerPublicId');
            $body = JsonRequestBody::requireObject($request);
            $input = CreateCustomerContactRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var CustomerContactDetails $created */
            $created = $this->transaction->run(fn (): CustomerContactDetails => $this->createCustomerContact->execute(
                actor: $actor,
                customerPublicId: $customerPublicId,
                displayName: $input->displayName,
                email: $input->email,
                position: $input->position,
                phone: $input->phone,
                mobile: $input->mobile,
                isPrimary: $input->isPrimary,
                isActive: $input->isActive,
                legacyContactId: $input->legacyContactId,
            ));

            return $this->responder->success(CustomerManagementDtoSerializer::customerContactDetails($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
