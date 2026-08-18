<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\DTO\GuestLoginEligibility;
use TriCab\Core\Identity\Contract\GuestLoginEligibilityLookup;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * In-process OTP eligibility using identity.Users only.
 * Customer/contact linkage is not required for authentication.
 */
final class CompositeGuestLoginEligibilityLookup implements GuestLoginEligibilityLookup
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function evaluate(EmailAddress $emailAddress): GuestLoginEligibility
    {
        $user = $this->users->findByEmail($emailAddress);
        if ($user === null) {
            return GuestLoginEligibility::notFound();
        }

        return GuestLoginEligibility::fromFlags(
            userExists: true,
            activeUser: $user->isAuthenticationAllowed(),
            hasLoginPermission: $user->hasPermission(ProtectedIdentityCatalog::accountLogin()),
        );
    }
}
