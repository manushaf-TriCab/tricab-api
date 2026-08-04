<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class ChangeCustomerStatusService
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {
    }

    public function execute(ActorContext $actor, string $publicId, string $status): CustomerDetails
    {
        $id = CustomerPublicId::fromString($publicId);

        if ($this->customers->findDetailsByPublicId($id) === null) {
            throw CustomerNotFound::forPublicId($id->toString());
        }

        $newStatus = CustomerStatus::fromString($status);

        return $this->customers->changeStatus(
            publicId: $id,
            status: $newStatus,
            actorUserId: UserId::fromInt($actor->userId),
        );
    }
}
