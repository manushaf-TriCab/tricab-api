<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\Exception\InactiveContactPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InactiveCustomerPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\Service\PortalAccessPolicy;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Grants a contact customer-portal access, moving it to "invited" (default)
 * or directly to "active" when $activateImmediately is true.
 */
final class EnableCustomerPortalAccessService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
        private readonly CustomerRepository $customers,
        private readonly PortalAccessPolicy $policy = new PortalAccessPolicy(),
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $contactPublicId,
        bool $activateImmediately = false,
    ): CustomerContactDetails {
        $id = CustomerContactPublicId::fromString($contactPublicId);
        $contact = $this->contacts->findDetailsByPublicId($id);

        if ($contact === null) {
            throw CustomerContactNotFound::forPublicId($id->toString());
        }

        if (!$contact->isActive) {
            throw InactiveContactPortalAccess::forContact($id->toString());
        }

        if ($contact->email === null) {
            throw InvalidCustomerValue::for('Email', '');
        }

        $customerId = CustomerPublicId::fromString($contact->customerPublicId);
        $customer = $this->customers->findDetailsByPublicId($customerId);

        if ($customer === null) {
            throw CustomerNotFound::forPublicId($customerId->toString());
        }

        if (!$customer->isActive) {
            throw InactiveCustomerPortalAccess::forCustomer($customerId->toString());
        }

        $current = PortalAccessStatus::fromString($contact->portalAccessStatus);
        $target = $this->policy->enableTarget($activateImmediately);
        $this->policy->assertTransitionAllowed($current, $target);

        return $this->contacts->changePortalAccessStatus(
            publicId: $id,
            status: $target,
            actorUserId: UserId::fromInt($actor->userId),
        );
    }
}
