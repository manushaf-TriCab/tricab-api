<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use Throwable;
use TriCab\Core\Customer\Application\DTO\CustomerPortalUserProvisioningResult;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Application\Exception\PortalUserProvisioningFailed;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Contract\CustomerPortalUserProvisioner;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\Exception\DuplicateContactUserLink;
use TriCab\Core\Customer\Domain\Exception\InactiveContactPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InactiveCustomerPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerRoleAssignment;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\Service\PortalAccessPolicy;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Core orchestration use case: provisions a brand-new Identity user for a
 * customer contact, assigns a customer-facing role, links the contact to the
 * new user as primary, and grants customer-portal access.
 *
 * Steps:
 *  1. Load contact by public id; fail if missing.
 *  2. Require an email address on the contact.
 *  3. Require the customer and the contact to both be active.
 *  4. Validate the requested role is CUSTOMER_USER or CUSTOMER_ADMIN.
 *  5. Provision the Identity user via CustomerPortalUserProvisioner.
 *  6. Link the contact to the new user as primary.
 *  7. Grant portal access (invited, or active when $activateImmediately).
 */
final class CreateCustomerPortalUserService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
        private readonly CustomerRepository $customers,
        private readonly CustomerContactUserRepository $links,
        private readonly CustomerPortalUserProvisioner $provisioner,
        private readonly PortalAccessPolicy $policy = new PortalAccessPolicy(),
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $contactPublicId,
        string $roleCode,
        bool $activateImmediately = true,
    ): CustomerPortalUserProvisioningResult {
        $contactPublicIdVo = CustomerContactPublicId::fromString($contactPublicId);
        $contact = $this->contacts->findDetailsByPublicId($contactPublicIdVo);

        if ($contact === null) {
            throw CustomerContactNotFound::forPublicId($contactPublicIdVo->toString());
        }

        if ($contact->email === null) {
            throw InvalidCustomerValue::for('Email', '');
        }

        if (!$contact->isActive) {
            throw InactiveContactPortalAccess::forContact($contactPublicIdVo->toString());
        }

        $customerPublicId = CustomerPublicId::fromString($contact->customerPublicId);
        $customer = $this->customers->findDetailsByPublicId($customerPublicId);

        if ($customer === null) {
            throw CustomerNotFound::forPublicId($customerPublicId->toString());
        }

        if (!$customer->isActive) {
            throw InactiveCustomerPortalAccess::forCustomer($customerPublicId->toString());
        }

        $role = RoleCode::fromString($roleCode);
        if (!ProtectedIdentityCatalog::isCustomerFacingRole($role)) {
            throw InvalidCustomerRoleAssignment::forRoleCode($role->toString());
        }

        $email = EmailAddress::fromString($contact->email);
        $actorUserId = UserId::fromInt($actor->userId);

        try {
            $provisioned = $this->provisioner->createActivePortalUser(
                email: $email,
                displayName: $contact->displayName,
                customerRole: $role,
                actorUserId: $actorUserId,
            );
        } catch (Throwable $throwable) {
            throw PortalUserProvisioningFailed::forEmail($email->toString(), $throwable);
        }

        $contactId = $this->contacts->findIdByPublicId($contactPublicIdVo);
        if ($contactId === null) {
            throw CustomerContactNotFound::forPublicId($contactPublicIdVo->toString());
        }

        if ($this->links->hasActiveLink($contactId, $provisioned->userId)) {
            throw DuplicateContactUserLink::forContactAndUser(
                $contactPublicIdVo->toString(),
                $provisioned->userPublicId->toString(),
            );
        }

        if ($this->links->findActivePrimaryUser($contactId) !== null) {
            throw DuplicateContactUserLink::primaryAlreadyAssigned($contactPublicIdVo->toString());
        }

        $link = $this->links->link(
            contactId: $contactId,
            userId: $provisioned->userId,
            isPrimary: true,
            createdByUserId: $actorUserId,
        );

        // A freshly provisioned contact starts "not-invited", which per the
        // PortalAccessPolicy transition table can only move to "invited" or
        // "declined" first; reaching "active" requires passing through
        // "invited". Perform that intermediate hop transparently here so
        // callers can still request immediate activation in one call.
        $current = PortalAccessStatus::fromString($contact->portalAccessStatus);
        $invitedStatus = PortalAccessStatus::invited();
        $this->policy->assertTransitionAllowed($current, $invitedStatus);

        $updatedContact = $this->contacts->changePortalAccessStatus(
            publicId: $contactPublicIdVo,
            status: $invitedStatus,
            actorUserId: $actorUserId,
        );

        if ($activateImmediately) {
            $activeStatus = PortalAccessStatus::active();
            $this->policy->assertTransitionAllowed($invitedStatus, $activeStatus);

            $updatedContact = $this->contacts->changePortalAccessStatus(
                publicId: $contactPublicIdVo,
                status: $activeStatus,
                actorUserId: $actorUserId,
            );
        }

        return new CustomerPortalUserProvisioningResult(
            contact: $updatedContact,
            link: $link,
            userPublicId: $provisioned->userPublicId->toString(),
            emailAddress: $provisioned->email->toString(),
            displayName: $provisioned->displayName,
        );
    }
}
