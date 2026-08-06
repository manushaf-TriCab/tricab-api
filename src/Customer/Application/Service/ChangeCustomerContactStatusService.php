<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class ChangeCustomerContactStatusService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
    ) {
    }

    public function execute(ActorContext $actor, string $contactPublicId, bool $isActive): CustomerContactDetails
    {
        $id = CustomerContactPublicId::fromString($contactPublicId);

        if ($this->contacts->findDetailsByPublicId($id) === null) {
            throw CustomerContactNotFound::forPublicId($id->toString());
        }

        return $this->contacts->changeStatus(
            publicId: $id,
            isActive: $isActive,
            actorUserId: UserId::fromInt($actor->userId),
        );
    }
}
