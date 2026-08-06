<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\ChangePortalAccessRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Service\DisableCustomerPortalAccessService;
use TriCab\Core\Customer\Application\Service\EnableCustomerPortalAccessService;

final class ChangeCustomerPortalAccessAction
{
    public function __construct(
        private readonly EnableCustomerPortalAccessService $enablePortalAccess,
        private readonly DisableCustomerPortalAccessService $disablePortalAccess,
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
            $input = ChangePortalAccessRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var CustomerContactDetails $updated */
            $updated = $this->transaction->run(fn (): CustomerContactDetails => $input->enabled
                ? $this->enablePortalAccess->execute(
                    actor: $actor,
                    contactPublicId: $contactPublicId,
                    activateImmediately: $input->activateImmediately,
                )
                : $this->disablePortalAccess->execute(
                    actor: $actor,
                    contactPublicId: $contactPublicId,
                ));

            return $this->responder->success(CustomerManagementDtoSerializer::customerContactDetails($updated));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
