<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\CreateCustomerPortalUserRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\DTO\CustomerPortalUserProvisioningResult;
use TriCab\Core\Customer\Application\Service\CreateCustomerPortalUserService;

final class CreateCustomerPortalUserAction
{
    public function __construct(
        private readonly CreateCustomerPortalUserService $createCustomerPortalUser,
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
            $input = CreateCustomerPortalUserRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var CustomerPortalUserProvisioningResult $created */
            $created = $this->transaction->run(
                fn (): CustomerPortalUserProvisioningResult => $this->createCustomerPortalUser->execute(
                    actor: $actor,
                    contactPublicId: $contactPublicId,
                    roleCode: $input->roleCode,
                    activateImmediately: $input->activateImmediately,
                ),
            );

            return $this->responder->success(
                CustomerManagementDtoSerializer::customerPortalUserProvisioningResult($created),
                201,
            );
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
