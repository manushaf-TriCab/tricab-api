<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Domain\Service\PortalAccessPolicy;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Revokes a contact's customer-portal access, moving it to "suspended".
 */
final class DisableCustomerPortalAccessService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
        private readonly PortalAccessPolicy $policy = new PortalAccessPolicy(),
    ) {
    }

    public function execute(ActorContext $actor, string $contactPublicId): CustomerContactDetails
    {
        $id = CustomerContactPublicId::fromString($contactPublicId);
        $contact = $this->contacts->findDetailsByPublicId($id);

        if ($contact === null) {
            throw CustomerContactNotFound::forPublicId($id->toString());
        }

        $current = PortalAccessStatus::fromString($contact->portalAccessStatus);
        $target = $this->policy->disableTarget();
        $this->policy->assertTransitionAllowed($current, $target);

        return $this->contacts->changePortalAccessStatus(
            publicId: $id,
            status: $target,
            actorUserId: UserId::fromInt($actor->userId),
        );
    }
}
