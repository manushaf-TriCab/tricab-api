<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;

final class ListCustomerPortalUsersAction
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerContactUserRepository $links,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $customerPublicId = RouteParams::require($request, 'customerPublicId');
            $publicId = CustomerPublicId::fromString($customerPublicId);
            $customerId = $this->customers->findIdByPublicId($publicId);

            if ($customerId === null) {
                throw CustomerNotFound::forPublicId($publicId->toString());
            }

            $links = $this->links->listPortalUsersForCustomer($customerId);

            return $this->responder->success([
                'items' => array_map(CustomerManagementDtoSerializer::customerContactUserLinkDetails(...), $links),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
