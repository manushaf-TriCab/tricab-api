<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class UpdateCustomerContactService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $contactPublicId,
        string $displayName,
        ?string $email = null,
        ?string $position = null,
        ?string $phone = null,
        ?string $mobile = null,
    ): CustomerContactDetails {
        $id = CustomerContactPublicId::fromString($contactPublicId);

        if ($this->contacts->findDetailsByPublicId($id) === null) {
            throw CustomerContactNotFound::forPublicId($id->toString());
        }

        $name = CreateCustomerContactService::requireDisplayName($displayName);
        $emailAddress = $email !== null ? EmailAddress::fromString($email) : null;

        return $this->contacts->update(
            publicId: $id,
            displayName: $name,
            email: $emailAddress,
            position: $position,
            phone: $phone,
            mobile: $mobile,
            actorUserId: UserId::fromInt($actor->userId),
        );
    }
}
