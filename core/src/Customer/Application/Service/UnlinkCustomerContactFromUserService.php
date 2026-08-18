<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerContactUserLinkNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class UnlinkCustomerContactFromUserService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
        private readonly CustomerContactUserRepository $links,
    ) {
    }

    public function execute(ActorContext $actor, string $contactPublicId, int $userId): void
    {
        $contactPublicIdVo = CustomerContactPublicId::fromString($contactPublicId);
        $contactId = $this->contacts->findIdByPublicId($contactPublicIdVo);

        if ($contactId === null) {
            throw CustomerContactNotFound::forPublicId($contactPublicIdVo->toString());
        }

        $targetUser = UserId::fromInt($userId);

        if (!$this->links->hasActiveLink($contactId, $targetUser)) {
            throw CustomerContactUserLinkNotFound::forContactAndUser($contactPublicIdVo->toString(), (string) $userId);
        }

        $this->links->unlink($contactId, $targetUser);
    }
}
