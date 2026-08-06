<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class CustomerPortalUserProvisioningResult
{
    public function __construct(
        public CustomerContactDetails $contact,
        public CustomerContactUserLinkDetails $link,
        public string $userPublicId,
        public string $emailAddress,
        public string $displayName,
    ) {
    }
}
