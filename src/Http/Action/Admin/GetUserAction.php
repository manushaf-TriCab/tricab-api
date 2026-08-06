<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserCustomerAssociation;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserTypeClassifier;
use TriCab\Core\Identity\Application\Service\UserManagement\GetUserDetailsService;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class GetUserAction
{
    public function __construct(
        private readonly GetUserDetailsService $getUserDetails,
        private readonly UserManagementRepository $users,
        private readonly CustomerContactUserRepository $links,
        private readonly CustomerContactRepository $contacts,
        private readonly CustomerRepository $customers,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'publicId');
            $details = $this->getUserDetails->execute($publicId);
            $details = $this->withCustomerAssociations($details);

            return $this->responder->success(UserManagementDtoSerializer::userDetails($details));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    private function withCustomerAssociations(UserDetails $details): UserDetails
    {
        $userId = $this->users->findUserIdByPublicId(UserPublicId::fromString($details->publicId));
        if ($userId === null) {
            return $details;
        }

        $roleCodes = [];
        foreach ($details->roles as $role) {
            if ($role->isActive && !$role->isExpired) {
                $roleCodes[] = $role->roleCode;
            }
        }

        $associations = [];
        foreach ($this->links->listActiveLinksForUser($userId) as $link) {
            $contact = $this->contacts->findDetailsByPublicId(
                CustomerContactPublicId::fromString($link->contactPublicId),
            );
            $customer = $this->customers->findDetailsByPublicId(
                CustomerPublicId::fromString($link->customerPublicId),
            );

            $associations[] = new UserCustomerAssociation(
                customerPublicId: $link->customerPublicId,
                customerName: $customer !== null ? $customer->customerName : $link->customerPublicId,
                contactPublicId: $link->contactPublicId,
                contactDisplayName: $contact !== null ? $contact->displayName : $link->contactPublicId,
                portalAccessStatus: $contact !== null ? $contact->portalAccessStatus : null,
                isPrimary: $link->isPrimary,
                isActive: $link->isActive,
                linkedAtUtc: $link->linkedAtUtc,
            );
        }

        return new UserDetails(
            publicId: $details->publicId,
            emailAddress: $details->emailAddress,
            displayName: $details->displayName,
            status: $details->status,
            roles: $details->roles,
            effectivePermissions: $details->effectivePermissions,
            identityProviders: $details->identityProviders,
            createdAtUtc: $details->createdAtUtc,
            updatedAtUtc: $details->updatedAtUtc,
            lastLoginAtUtc: $details->lastLoginAtUtc,
            recentAuditEvents: $details->recentAuditEvents,
            customerAssociations: $associations,
            userType: $details->userType ?? UserTypeClassifier::fromRoleCodes($roleCodes),
        );
    }
}
