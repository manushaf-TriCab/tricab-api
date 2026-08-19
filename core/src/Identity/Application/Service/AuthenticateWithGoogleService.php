<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\GoogleIdTokenInvalid;
use TriCab\Core\Identity\Contract\GoogleIdTokenValidator;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\GoogleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/** Validates a Google ID token and resolves an existing TriCab user. */
final class AuthenticateWithGoogleService
{
    public function __construct(
        private readonly GoogleIdTokenValidator $googleIdTokenValidator,
        private readonly IdentityResolutionService $identityResolution,
    ) {
    }

    public function execute(string $idToken): User
    {
        $verified = $this->googleIdTokenValidator->validate($idToken);

        if (!$verified->emailVerified) {
            throw GoogleIdTokenInvalid::emailNotVerified();
        }

        $emailAddress = EmailAddress::fromString($verified->emailAddress);
        $googleIdentity = GoogleExternalIdentityFactory::forSubject($verified->subjectId);

        return $this->identityResolution->resolve($googleIdentity, $emailAddress);
    }
}
