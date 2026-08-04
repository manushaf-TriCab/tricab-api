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
use TriCab\Api\Http\Request\Admin\UpdateCustomerContactRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Service\UpdateCustomerContactService;

final class UpdateCustomerContactAction
{
    public function __construct(
        private readonly UpdateCustomerContactService $updateCustomerContact,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $contactPublicId = RouteParams::require($request, 'contactPublicId');
            $body = JsonRequestBody::requireObject($request);
            $input = UpdateCustomerContactRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var CustomerContactDetails $updated */
            $updated = $this->transaction->run(fn (): CustomerContactDetails => $this->updateCustomerContact->execute(
                actor: $actor,
                contactPublicId: $contactPublicId,
                displayName: $input->displayName,
                email: $input->email,
                position: $input->position,
                phone: $input->phone,
                mobile: $input->mobile,
            ));

            return $this->responder->success(CustomerManagementDtoSerializer::customerContactDetails($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
